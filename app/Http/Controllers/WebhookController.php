<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\TapPaymentService;
use App\Services\PaylinkClient;
use App\Services\PersonaService;
use App\Services\PersonaKycHandler;
use App\Notifications\PaymentConfirmed;
use App\Notifications\OrderStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\MessageHelper;

class WebhookController extends Controller
{
    public function __construct(
        private TapPaymentService $tapService,
        private PaylinkClient $paylinkClient,
        private PersonaService $personaService,
        private PersonaKycHandler $kycHandler
    ) {}

    /**
     * DEPRECATED: Tap payment webhook - No longer used (migrated to Paylink)
     * Kept for historical reference only
     * 
     * @deprecated Use Paylink webhook instead
     */
    public function tap(Request $request)
    {
        Log::warning('Deprecated Tap payment webhook called - migrated to Paylink', [
            'payload' => $request->all(),
        ]);
        
        return response()->json([
            'message' => 'This webhook is deprecated. Please use Paylink webhook instead.',
            'status' => 'deprecated',
        ], 410); // 410 Gone - resource no longer available
    }

    public function persona(Request $request)
    {
        $payload = $request->all();
        $eventId = $payload['data']['id'] ?? null; // Persona event ID for replay protection
        $eventName = $payload['data']['attributes']['name'] ?? null;
        
        Log::info('Persona Webhook Received', [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'payload_structure' => [
                'has_data' => isset($payload['data']),
                'has_attributes' => isset($payload['data']['attributes']),
                'has_payload' => isset($payload['data']['attributes']['payload']),
            ],
        ]);

        // Verify webhook signature if configured
        if (config('services.persona.webhook_secret')) {
            $signature = $request->header('X-Persona-Signature');
            if (!$this->personaService->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Persona Webhook Signature Invalid', [
                    'event_id' => $eventId,
                    'has_signature' => !empty($signature),
                ]);
                return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_SIGNATURE], 401);
            }
        }

        // Filter events - only process inquiry-related events
        $allowedEvents = ['inquiry.completed', 'inquiry.failed', 'inquiry.canceled', 'inquiry.expired'];
        if ($eventName && !in_array($eventName, $allowedEvents, true)) {
            Log::info('Persona Webhook: Ignoring event', [
                'event_name' => $eventName,
                'event_id' => $eventId,
            ]);
            return response()->json(['message' => 'Event ignored'], 200);
        }

        try {
            $processed = $this->kycHandler->processPayload($payload, $eventId);

            if (!$processed) {
                Log::warning('Persona Webhook: Processing failed', [
                    'event_id' => $eventId,
                    'event_name' => $eventName,
                ]);
                return response()->json(['message' => MessageHelper::WEBHOOK_KYC_NOT_FOUND], 404);
            }

            Log::info('Persona Webhook: Successfully processed', [
                'event_id' => $eventId,
                'event_name' => $eventName,
                'kyc_id' => $processed->id,
                'kyc_status' => $processed->status,
                'user_id' => $processed->user_id,
            ]);

            return response()->json(['message' => MessageHelper::WEBHOOK_PROCESSED]);
        } catch (\Throwable $e) {
            Log::error('Persona Webhook: Processing error', [
                'event_id' => $eventId,
                'event_name' => $eventName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
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

        // Find payment by transaction number
        $payment = Payment::where('paylink_transaction_no', $transactionNo)->first();

        if (!$payment) {
            // Check if this is a test webhook (Paylink sends test transactions)
            $isTest = $payload['test'] ?? false;
            $isTestTransaction = str_contains(strtolower($transactionNo ?? ''), 'test') || 
                                str_contains(strtolower($transactionNo ?? ''), 'webhook') ||
                                empty($transactionNo);
            
            if ($isTest || $isTestTransaction) {
                Log::info('Paylink Test Webhook Received', [
                    'transaction_no' => $transactionNo,
                    'payload' => $payload,
                ]);
                // Return success for test webhooks
                return response()->json([
                    'message' => 'Test webhook received successfully - endpoint is working',
                    'status' => 'success',
                    'test' => true,
                ], 200);
            }
            
            Log::warning('Paylink Webhook: Payment not found', [
                'transaction_no' => $transactionNo,
                'payload' => $payload,
            ]);
            // Return 200 instead of 404 to prevent Paylink from retrying
            // This handles cases where webhook is sent before payment record exists
            return response()->json([
                'message' => 'Payment not found - webhook endpoint is working, but payment record does not exist',
                'status' => 'ignored',
                'note' => 'This is normal for test webhooks or if webhook arrives before payment record is created',
            ], 200);
        }

        $order = $payment->order;

        // SECURITY: Always re-query invoice status from Paylink API (never trust webhook payload alone)
        try {
            $invoice = $this->paylinkClient->getInvoice($transactionNo);
            
            $orderStatus = $invoice['orderStatus'] ?? null;
            $paidAmount = $invoice['paidAmount'] ?? $invoice['amountPaid'] ?? 0;
            $invoiceAmount = $invoice['amount'] ?? 0;
            $hasPaymentReceipt = !empty($invoice['paymentReceipt']);
            $paymentReceipt = $invoice['paymentReceipt'] ?? null;

            Log::info('Paylink Webhook: Invoice status', [
                'transaction_no' => $transactionNo,
                'order_id' => $order->id,
                'order_status' => $orderStatus,
                'paid_amount' => $paidAmount,
                'invoice_amount' => $invoiceAmount,
                'has_payment_receipt' => $hasPaymentReceipt,
                'payment_receipt' => $paymentReceipt,
            ]);

            // Update payment record
            $payment->paylink_response = array_merge($payment->paylink_response ?? [], $invoice);
            $payment->webhook_payload = $payload;

            // Handle payment status
            // Payment is confirmed if:
            // 1. orderStatus is 'Paid' AND paidAmount > 0, OR
            // 2. orderStatus is 'Paid' AND paymentReceipt exists (payment receipt confirms payment even if paidAmount is 0 in test)
            $isPaid = ($orderStatus === 'Paid' || $orderStatus === 'paid') && 
                     (($paidAmount > 0) || $hasPaymentReceipt);
            
            if ($isPaid) {
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
                        // If paidAmount is 0 but paymentReceipt exists, trust the receipt (common in test mode)
                        if ($paidAmount > 0) {
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
                        } elseif (!$hasPaymentReceipt) {
                            // No paid amount and no receipt - don't process
                            Log::warning('Paylink: Payment status is Paid but no paidAmount or paymentReceipt', [
                                'order_id' => $order->id,
                                'transaction_no' => $transactionNo,
                            ]);
                            return;
                        }
                        
                        // Log payment confirmation
                        if ($hasPaymentReceipt) {
                            Log::info('Paylink: Payment confirmed via payment receipt', [
                                'order_id' => $order->id,
                                'transaction_no' => $transactionNo,
                                'payment_method' => $paymentReceipt['paymentMethod'] ?? null,
                                'payment_date' => $paymentReceipt['paymentDate'] ?? null,
                                'paid_amount' => $paidAmount,
                            ]);
                        }

                        // Get buyer wallet with lock
                        $buyerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->buyer_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );

                        // CRITICAL: Log wallet state before processing to track any anomalies
                        $walletBefore = [
                            'available_balance' => $buyerWallet->available_balance,
                            'on_hold_balance' => $buyerWallet->on_hold_balance,
                        ];

                        // Payment comes from Paylink (external payment gateway)
                        // Since payment is already collected externally, we directly credit escrow
                        // The funds never enter available_balance - they go straight to escrow for protection
                        // CRITICAL: NEVER add to available_balance for buyer payments - only escrow!
                        $buyerWallet->on_hold_balance += $order->amount;
                        $buyerWallet->save();

                        // Log wallet state after processing for audit trail
                        Log::info('Paylink Webhook: Buyer wallet updated', [
                            'order_id' => $order->id,
                            'buyer_id' => $order->buyer_id,
                            'amount' => $order->amount,
                            'wallet_before' => $walletBefore,
                            'wallet_after' => [
                                'available_balance' => $buyerWallet->available_balance,
                                'on_hold_balance' => $buyerWallet->on_hold_balance,
                            ],
                            'note' => 'Payment added to escrow (on_hold_balance) - NOT available_balance',
                        ]);

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
                        $listingSold = false;
                        if ($listing && $listing->status === 'active') {
                            $listing->status = 'sold';
                            $listing->save();
                            $listingSold = true;
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
                        if ($listingSold && $listing) {
                            $order->seller->notify(new \App\Notifications\AccountSold($listing, $order));
                        }

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
