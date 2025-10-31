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
use App\Services\PersonaService;
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
        private PersonaService $personaService
    ) {}

    public function tap(Request $request)
    {
        $payload = $request->all();
        
        Log::info('Tap Webhook Received', ['payload' => $payload]);

        // Verify webhook signature if configured
        if (config('services.tap.webhook_secret')) {
            $signature = $request->header('X-Tap-Signature');
            if (!$this->tapService->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Tap Webhook Signature Invalid');
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

        // Update payment
        $payment->status = match($status) {
            'CAPTURED' => 'captured',
            'AUTHORIZED' => 'authorized',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            default => $payment->status,
        };

        $payment->webhook_payload = $payload;
        
        if ($status === 'CAPTURED') {
            $payment->captured_at = now();
        }

        $payment->save();

        $order = $payment->order;

        // Handle CAPTURED status - credit buyer and move to escrow
        if ($status === 'CAPTURED' && $order->status === 'pending') {
            // Wrap in transaction to prevent race conditions
            DB::transaction(function () use ($order) {
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

                // Update order
                $oldStatus = $order->status;
                $order->status = 'escrow_hold';
                $order->paid_at = now();
                $order->escrow_hold_at = now();
                $order->escrow_release_at = now()->addHours(12);
                $order->save();

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

        if (!$inquiryId) {
            return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_PAYLOAD], 400);
        }

        $kyc = KycVerification::where('persona_inquiry_id', $inquiryId)->first();

        if (!$kyc) {
            Log::warning('Persona Webhook: KYC not found', ['inquiry_id' => $inquiryId]);
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
}
