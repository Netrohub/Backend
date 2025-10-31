<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\KycVerification;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TapPaymentService;
use App\Services\PersonaService;
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

        // Handle CAPTURED status - move to escrow
        if ($status === 'CAPTURED' && $order->status === 'pending') {
            // Wrap in transaction to prevent race conditions
            DB::transaction(function () use ($order) {
                // Move funds from buyer to escrow (on_hold)
                $buyerWallet = Wallet::lockForUpdate()
                    ->firstOrCreate(
                        ['user_id' => $order->buyer_id],
                        ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                    );

                // Validate buyer has sufficient balance
                if ($buyerWallet->available_balance < $order->amount) {
                    Log::error('Insufficient buyer balance for escrow', [
                        'buyer_id' => $order->buyer_id,
                        'order_id' => $order->id,
                        'required' => $order->amount,
                        'available' => $buyerWallet->available_balance,
                    ]);
                    throw new \Exception('Insufficient buyer balance for escrow');
                }

                $buyerWallet->available_balance -= $order->amount;
                $buyerWallet->on_hold_balance += $order->amount;
                $buyerWallet->save();

                // Update order
                $order->status = 'escrow_hold';
                $order->paid_at = now();
                $order->escrow_hold_at = now();
                $order->escrow_release_at = now()->addHours(12);
                $order->save();

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
        }

        $kyc->persona_data = array_merge($kyc->persona_data ?? [], $payload);
        $kyc->save();

        return response()->json(['message' => MessageHelper::WEBHOOK_PROCESSED]);
    }
}
