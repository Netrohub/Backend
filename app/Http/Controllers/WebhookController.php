<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\KycVerification;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\TapPaymentService;
use App\Services\PaylinkClient;
use App\Services\PersonaService;
use App\Notifications\PaymentConfirmed;
use App\Notifications\OrderStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\MessageHelper;
use App\Helpers\AuditHelper;

class WebhookController extends Controller
{
    public function __construct(
        private TapPaymentService $tapService,
        private PaylinkClient $paylinkClient,
        private PersonaService $personaService
    ) {}

    public function tap(Request $request)
    {
        $payload = $request->all();
        
        Log::info('Tap Webhook Received', ['payload' => $payload]);

        // Verify webhook hashstring if configured
        if (config('services.tap.webhook_secret')) {
            $hashstring = $request->header('hashstring');
            if (!$this->tapService->verifyWebhookSignature($payload, $hashstring)) {
                Log::warning('Tap Webhook Hashstring Invalid', [
                    'received_hashstring' => $hashstring,
                    'charge_id' => $payload['id'] ?? null,
                ]);
                return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_SIGNATURE], 401);
            }
        }

        $chargeId = $payload['id'] ?? $payload['object']['id'] ?? null;
        $status = $payload['status'] ?? $payload['object']['status'] ?? null;

        if (!$chargeId) {
            return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_PAYLOAD], 400);
        }

        $payment = Payment::where('tap_charge_id', $chargeId)->first();

        if (!$payment) {
            Log::warning('Tap Webhook: Payment not found', ['charge_id' => $chargeId]);
            return response()->json(['message' => MessageHelper::WEBHOOK_PAYMENT_NOT_FOUND], 404);
        }

        $order = $payment->order;

        // SECURITY: Check if payment already processed BEFORE updating (prevent webhook replay attacks)
        if ($status === 'CAPTURED' && $payment->status === 'captured') {
            Log::warning('Duplicate CAPTURED webhook received - payment already processed', [
                'charge_id' => $chargeId,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
            ]);
            return response()->json(['message' => 'Payment already processed'], 200);
        }

        // Update payment status (only if not already captured)
        if ($payment->status !== 'captured') {
            $payment->status = match($status) {
                'CAPTURED' => 'captured',
                'AUTHORIZED' => 'authorized',
                'FAILED' => 'failed',
                'CANCELLED' => 'cancelled',
                default => $payment->status,
            };
        }

        $payment->webhook_payload = $payload;
        
        if ($status === 'CAPTURED' && !$payment->captured_at) {
            $payment->captured_at = now();
        }

        $payment->save();

        // Handle CAPTURED status - THIS IS WHEN ORDER BECOMES REAL (after payment confirmation)
        // Only process if order is still in payment_intent status (not yet a real order)
        if ($status === 'CAPTURED' && $order->status === 'payment_intent') {

            // Wrap in transaction with pessimistic locking to prevent race conditions
            DB::transaction(function () use ($order, $payment, $request, $chargeId) {
                // SECURITY: Reload order with lock to prevent concurrent processing
                $order = Order::lockForUpdate()->find($order->id);
                
                // Double-check status after lock (prevents duplicate processing)
                if ($order->status !== 'payment_intent') {
                    Log::warning('Order status changed before webhook processing', [
                        'order_id' => $order->id,
                        'current_status' => $order->status,
                    ]);
                    return; // Already processed by another webhook
                }

                // Reload payment with lock
                $payment = \App\Models\Payment::lockForUpdate()->find($payment->id);
                
                // Double-check payment status
                if ($payment->status === 'captured') {
                    Log::warning('Payment already captured before webhook processing', [
                        'payment_id' => $payment->id,
                    ]);
                    return; // Already processed
                }

                // Get buyer wallet with lock
                $buyerWallet = Wallet::lockForUpdate()
                    ->firstOrCreate(
                        ['user_id' => $order->buyer_id],
                        ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                    );

                // Payment comes from Tap Payments (external payment gateway)
                // Since payment is already collected externally, we directly credit escrow
                // The funds never enter available_balance - they go straight to escrow for protection
                $buyerWallet->on_hold_balance += $order->amount;
                $buyerWallet->save();

                // CRITICAL: This is when the order becomes REAL (changes from payment_intent to escrow_hold)
                // payment_intent = temporary, not a real order
                // escrow_hold = real order, payment confirmed
                $oldStatus = $order->status;
                $order->status = 'escrow_hold';
                $order->paid_at = now();
                $order->escrow_hold_at = now();
                $order->escrow_release_at = now()->addHours(12);
                $order->save();

                // Update payment status to captured
                $payment->status = 'captured';
                $payment->captured_at = now();
                $payment->save();

                // Mark listing as sold only after payment is confirmed (order is now real)
                $listing = $order->listing;
                if ($listing && $listing->status === 'active') {
                    $listing->status = 'sold';
                    $listing->save();
                }

                // Audit log for order status change (payment_intent -> escrow_hold = order becomes real)
                AuditHelper::log(
                    'order.payment_confirmed',
                    Order::class,
                    $order->id,
                    ['status' => $oldStatus, 'note' => 'Payment intent - not yet a real order'],
                    [
                        'status' => 'escrow_hold',
                        'paid_at' => $order->paid_at->toIso8601String(),
                        'escrow_hold_at' => $order->escrow_hold_at->toIso8601String(),
                        'note' => 'Order confirmed - payment received, now a real order',
                    ],
                    $request
                );

                // Send notifications
                $order->buyer->notify(new PaymentConfirmed($order));
                $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));
                $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));

                // Schedule escrow release job
                ReleaseEscrowFunds::dispatch($order->id)
                    ->delay(now()->addHours(12));
            });
        }

        return response()->json(['message' => MessageHelper::WEBHOOK_PROCESSED]);
    }

    public function persona(Request $request)
    {
        $payload = $request->all();
        
        Log::info('Persona Webhook Received', ['payload' => $payload]);

        // Verify webhook signature if configured
        if (config('services.persona.webhook_secret')) {
            $signature = $request->header('X-Persona-Signature');
            if (!$this->personaService->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Persona Webhook Signature Invalid');
                return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_SIGNATURE], 401);
            }
        }

        $inquiryId = $payload['data']['id'] ?? null;
        $status = $payload['data']['attributes']['status'] ?? null;
        $referenceId = $payload['data']['attributes']['reference-id'] ?? null;

        if (!$inquiryId) {
            return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_PAYLOAD], 400);
        }

        // Try to find existing KYC record
        $kyc = KycVerification::where('persona_inquiry_id', $inquiryId)->first();

        // If KYC doesn't exist, try to create it from reference-id
        // Reference ID format: "user_{user_id}"
        if (!$kyc && $referenceId && str_starts_with($referenceId, 'user_')) {
            $userId = (int) str_replace('user_', '', $referenceId);
            if ($userId > 0) {
                $user = User::find($userId);
                if ($user) {
                    // Create KYC record for this inquiry
                    $kyc = KycVerification::create([
                        'user_id' => $userId,
                        'persona_inquiry_id' => $inquiryId,
                        'status' => 'pending',
                        'persona_data' => $payload,
                    ]);
                    Log::info('Persona Webhook: Created KYC record from inquiry', [
                        'inquiry_id' => $inquiryId,
                        'user_id' => $userId,
                    ]);
                }
            }
        }

        if (!$kyc) {
            Log::warning('Persona Webhook: KYC not found and could not create', [
                'inquiry_id' => $inquiryId,
                'reference_id' => $referenceId,
            ]);
            return response()->json(['message' => MessageHelper::WEBHOOK_KYC_NOT_FOUND], 404);
        }

        // Update KYC status
        $kyc->status = match($status) {
            'completed.approved' => 'verified',
            'completed.declined' => 'failed',
            'expired' => 'expired',
            default => 'pending',
        };

        if ($kyc->status === 'verified') {
            $kyc->verified_at = now();
            
            // Update user verification status
            $user = $kyc->user;
            $user->is_verified = true;
            $user->save();
            
            // Send verification notification
            $user->notify(new \App\Notifications\KycVerified($kyc, true));
        } elseif ($kyc->status === 'failed' || $kyc->status === 'expired') {
            // Send notification for failed/expired KYC
            $user = $kyc->user;
            $user->notify(new \App\Notifications\KycVerified($kyc, false));
        }

        $kyc->persona_data = array_merge($kyc->persona_data ?? [], $payload);
        $kyc->save();

        return response()->json(['message' => MessageHelper::WEBHOOK_PROCESSED]);
    }

    /**
     * Handle Tap Payments transfer webhook (for withdrawal status updates)
     * Note: This endpoint should be added to routes if Tap sends transfer webhooks
     */
    public function tapTransfer(Request $request)
    {
        $payload = $request->all();
        
        Log::info('Tap Transfer Webhook Received', ['payload' => $payload]);

        // Verify webhook signature if configured
        if (config('services.tap.webhook_secret')) {
            $signature = $request->header('X-Tap-Signature');
            if (!$this->tapService->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Tap Transfer Webhook Signature Invalid');
                return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_SIGNATURE], 401);
            }
        }

        $transferId = $payload['id'] ?? $payload['object']['id'] ?? null;
        $status = $payload['status'] ?? $payload['object']['status'] ?? null;

        if (!$transferId) {
            return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_PAYLOAD], 400);
        }

        $withdrawalRequest = WithdrawalRequest::where('tap_transfer_id', $transferId)->first();

        if (!$withdrawalRequest) {
            Log::warning('Tap Transfer Webhook: Withdrawal request not found', ['transfer_id' => $transferId]);
            return response()->json(['message' => 'Withdrawal request not found'], 404);
        }

        // Update withdrawal request status
        $withdrawalRequest->tap_response = array_merge($withdrawalRequest->tap_response ?? [], $payload);
        
        if ($status === 'TRANSFERRED' || $status === 'COMPLETED') {
            $withdrawalRequest->status = WithdrawalRequest::STATUS_COMPLETED;
            $withdrawalRequest->processed_at = now();
        } elseif ($status === 'FAILED' || $status === 'CANCELLED') {
            $withdrawalRequest->status = WithdrawalRequest::STATUS_FAILED;
            $withdrawalRequest->failure_reason = $payload['failure_reason'] ?? $payload['message'] ?? 'Transfer failed';
            
            // Refund wallet balance if transfer failed
            DB::transaction(function () use ($withdrawalRequest) {
                $wallet = Wallet::lockForUpdate()->findOrFail($withdrawalRequest->wallet_id);
                $wallet->available_balance += $withdrawalRequest->amount;
                $wallet->withdrawn_total -= $withdrawalRequest->amount;
                $wallet->save();
            });
        } elseif ($status === 'PENDING' || $status === 'PROCESSING') {
            $withdrawalRequest->status = WithdrawalRequest::STATUS_PROCESSING;
        }

        $withdrawalRequest->save();

        Log::info('Withdrawal request updated', [
            'withdrawal_request_id' => $withdrawalRequest->id,
            'status' => $withdrawalRequest->status,
            'transfer_status' => $status,
        ]);

        return response()->json(['message' => MessageHelper::WEBHOOK_PROCESSED]);
    }

    /**
     * Handle Paylink payment webhook
     * Paylink sends webhook when payment status changes
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function paylink(Request $request)
    {
        $payload = $request->all();
        
        Log::info('Paylink Webhook Received', ['payload' => $payload]);

        $transactionNo = $payload['transactionNo'] ?? $payload['transaction_no'] ?? null;

        if (!$transactionNo) {
            Log::warning('Paylink Webhook: Missing transaction number', ['payload' => $payload]);
            return response()->json(['message' => 'Missing transaction number'], 400);
        }

        // Check if this is a test webhook (Paylink sends test transactions)
        $isTest = $payload['test'] ?? false;
        if ($isTest || str_contains(strtolower($transactionNo), 'test')) {
            Log::info('Paylink Test Webhook Received', [
                'transaction_no' => $transactionNo,
                'payload' => $payload,
            ]);
            // Return success for test webhooks
            return response()->json([
                'message' => 'Test webhook received successfully',
                'status' => 'ok',
            ], 200);
        }

        // Find payment by transaction number
        $payment = Payment::where('paylink_transaction_no', $transactionNo)->first();

        if (!$payment) {
            Log::warning('Paylink Webhook: Payment not found', [
                'transaction_no' => $transactionNo,
                'payload' => $payload,
            ]);
            // Return 200 instead of 404 to prevent Paylink from retrying
            // This handles cases where webhook is sent before payment record exists
            return response()->json([
                'message' => 'Payment not found - may be test webhook or payment not yet created',
                'status' => 'ignored',
            ], 200);
        }

        $order = $payment->order;

        // SECURITY: Always re-query invoice status from Paylink API (never trust webhook payload alone)
        try {
            $invoice = $this->paylinkClient->getInvoice($transactionNo);
            
            $orderStatus = $invoice['orderStatus'] ?? null;
            $paidAmount = $invoice['paidAmount'] ?? 0;
            $invoiceAmount = $invoice['amount'] ?? 0;

            Log::info('Paylink Webhook: Invoice status', [
                'transaction_no' => $transactionNo,
                'order_id' => $order->id,
                'order_status' => $orderStatus,
                'paid_amount' => $paidAmount,
                'invoice_amount' => $invoiceAmount,
            ]);

            // Update payment record
            $payment->paylink_response = array_merge($payment->paylink_response ?? [], $invoice);
            $payment->webhook_payload = $payload;

            // Handle payment status
            if ($orderStatus === 'Paid' && $paidAmount > 0) {
                // SECURITY: Check if payment already processed (prevent webhook replay attacks)
                if ($payment->status === 'captured') {
                    Log::warning('Duplicate Paid webhook received - payment already processed', [
                        'transaction_no' => $transactionNo,
                        'order_id' => $order->id,
                        'payment_id' => $payment->id,
                    ]);
                    return response()->json(['message' => 'Payment already processed'], 200);
                }

                // Only process if order is still in payment_intent status
                if ($order->status === 'payment_intent') {
                    // Wrap in transaction with pessimistic locking to prevent race conditions
                    DB::transaction(function () use ($order, $payment, $paidAmount, $invoiceAmount) {
                        // SECURITY: Reload order with lock to prevent concurrent processing
                        $order = Order::lockForUpdate()->find($order->id);
                        
                        // Double-check status after lock (prevents duplicate processing)
                        if ($order->status !== 'payment_intent') {
                            Log::warning('Order status changed before webhook processing', [
                                'order_id' => $order->id,
                                'current_status' => $order->status,
                            ]);
                            return; // Already processed by another webhook
                        }

                        // Reload payment with lock
                        $payment = Payment::lockForUpdate()->find($payment->id);
                        
                        // Double-check payment status
                        if ($payment->status === 'captured') {
                            Log::warning('Payment already captured before webhook processing', [
                                'payment_id' => $payment->id,
                            ]);
                            return; // Already processed
                        }

                        // Validate paid amount matches order amount (with small tolerance for currency conversion)
                        $expectedAmountSAR = round($order->amount * 3.75, 2); // USD to SAR rate
                        $amountDifference = abs($paidAmount - $expectedAmountSAR);
                        
                        if ($amountDifference > 0.50) { // Allow 0.50 SAR tolerance
                            Log::error('Paylink: Paid amount mismatch', [
                                'order_id' => $order->id,
                                'expected_sar' => $expectedAmountSAR,
                                'paid_sar' => $paidAmount,
                                'difference' => $amountDifference,
                            ]);
                            // Don't process payment if amount doesn't match
                            return;
                        }

                        // Get buyer wallet with lock
                        $buyerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->buyer_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );

                        // Payment comes from Paylink (external payment gateway)
                        // Since payment is already collected externally, we directly credit escrow
                        // The funds never enter available_balance - they go straight to escrow for protection
                        $buyerWallet->on_hold_balance += $order->amount;
                        $buyerWallet->save();

                        // CRITICAL: This is when the order becomes REAL (changes from payment_intent to escrow_hold)
                        // payment_intent = temporary, not a real order
                        // escrow_hold = real order, payment confirmed
                        $oldStatus = $order->status;
                        $order->status = 'escrow_hold';
                        $order->paid_at = now();
                        $order->escrow_hold_at = now();
                        $order->escrow_release_at = now()->addHours(12);
                        $order->save();

                        // Update payment status to captured
                        $payment->status = 'captured';
                        $payment->captured_at = now();
                        $payment->save();

                        // Mark listing as sold only after payment is confirmed (order is now real)
                        $listing = $order->listing;
                        if ($listing && $listing->status === 'active') {
                            $listing->status = 'sold';
                            $listing->save();
                        }

                        // Audit log for order status change
                        AuditHelper::log(
                            'order.payment_confirmed',
                            Order::class,
                            $order->id,
                            ['status' => $oldStatus, 'note' => 'Payment intent - not yet a real order'],
                            [
                                'status' => 'escrow_hold',
                                'paid_at' => $order->paid_at->toIso8601String(),
                                'escrow_hold_at' => $order->escrow_hold_at->toIso8601String(),
                                'note' => 'Order confirmed - payment received, now a real order',
                            ],
                            $request
                        );

                        // Send notifications
                        $order->buyer->notify(new PaymentConfirmed($order));
                        $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));
                        $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));

                        // Schedule escrow release job
                        ReleaseEscrowFunds::dispatch($order->id)
                            ->delay(now()->addHours(12));
                    });
                }

                // Update payment status
                $payment->status = 'captured';
                $payment->captured_at = now();
            } elseif ($orderStatus === 'Canceled' || $orderStatus === 'Failed') {
                $payment->status = 'failed';
            } elseif ($orderStatus === 'Pending') {
                $payment->status = 'initiated';
            }

            $payment->save();

            return response()->json(['message' => MessageHelper::WEBHOOK_PROCESSED]);
        } catch (\Exception $e) {
            Log::error('Paylink Webhook Error', [
                'transaction_no' => $transactionNo,
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json(['message' => 'Webhook processing error'], 500);
        }
    }
}
