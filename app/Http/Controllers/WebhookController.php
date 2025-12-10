<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\HyperPayService;
use App\Services\PersonaService;
use App\Services\PersonaKycHandler;
use App\Helpers\MadaHelper;
use App\Notifications\PaymentConfirmed;
use App\Notifications\OrderStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\MessageHelper;

class WebhookController extends Controller
{
    public function __construct(
        private HyperPayService $hyperPayService,
        private PersonaService $personaService,
        private PersonaKycHandler $kycHandler
    ) {}


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
     * Handle HyperPay webhook notifications
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function hyperpay(Request $request)
    {
        $payload = $request->all();
        
        Log::info('HyperPay Webhook Received', ['payload' => $payload]);

        // Verify webhook signature if configured
        if (config('services.hyperpay.webhook_secret')) {
            $signature = $request->header('X-HyperPay-Signature') 
                ?? $request->header('X-Signature')
                ?? null;
            
            if ($signature && !$this->hyperPayService->verifyWebhookSignature($payload, $signature)) {
                Log::warning('HyperPay Webhook Signature Invalid');
                return response()->json(['message' => MessageHelper::WEBHOOK_INVALID_SIGNATURE], 401);
            }
        }

        try {
            // Extract checkout ID and payment information from webhook
            $checkoutId = $payload['id'] ?? $payload['checkoutId'] ?? null;
            $merchantTransactionId = $payload['merchantTransactionId'] ?? $payload['merchantTransactionId'] ?? null;
            
            if (!$checkoutId && !$merchantTransactionId) {
                Log::warning('HyperPay Webhook: Missing checkout ID or transaction ID', ['payload' => $payload]);
                return response()->json(['message' => 'Missing required fields'], 400);
            }

            // Extract order ID from merchant transaction ID (format: NXO-{order_id})
            $orderId = null;
            if ($merchantTransactionId && preg_match('/^NXO-(\d+)$/', $merchantTransactionId, $matches)) {
                $orderId = (int) $matches[1];
            }

            // Find payment by checkout ID or order ID
            $payment = null;
            if ($checkoutId) {
                $payment = Payment::where('hyperpay_checkout_id', $checkoutId)->first();
            }
            
            if (!$payment && $orderId) {
                $payment = Payment::where('order_id', $orderId)
                    ->whereNotNull('hyperpay_checkout_id')
                    ->first();
            }

            if (!$payment) {
                Log::warning('HyperPay Webhook: Payment not found', [
                    'checkout_id' => $checkoutId,
                    'order_id' => $orderId,
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);
                return response()->json(['message' => MessageHelper::WEBHOOK_PAYMENT_NOT_FOUND], 404);
            }

            $order = $payment->order;
            if (!$order) {
                Log::warning('HyperPay Webhook: Order not found', ['payment_id' => $payment->id]);
                return response()->json(['message' => MessageHelper::WEBHOOK_ORDER_NOT_FOUND], 404);
            }

            // Get payment status from webhook payload
            $resultCode = $payload['result']['code'] ?? null;
            $resultDescription = $payload['result']['description'] ?? 'Unknown status';
            $paymentType = $payload['paymentType'] ?? null;
            $amount = $payload['amount'] ?? null;
            $currency = $payload['currency'] ?? null;
            $cardBin = $payload['card']['bin'] ?? null;
            $isMadaCard = MadaHelper::isMadaCard($cardBin);

            Log::info('HyperPay Webhook: Processing payment', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'checkout_id' => $checkoutId,
                'result_code' => $resultCode,
                'payment_type' => $paymentType,
                'card_bin' => $cardBin,
                'is_mada_card' => $isMadaCard,
            ]);

            // Update payment record with webhook payload
            $payment->webhook_payload = $payload;
            $payment->hyperpay_response = array_merge($payment->hyperpay_response ?? [], $payload);

            // Process payment based on result code
            if ($resultCode && $this->hyperPayService->isPaymentSuccessful($resultCode)) {
                // Payment successful
                if ($payment->status !== 'captured' && $order->status === 'payment_intent') {
                    DB::transaction(function () use ($order, $payment, $amount, $currency) {
                        // Reload with lock to prevent race conditions
                        $order = Order::lockForUpdate()->find($order->id);
                        
                        // Double-check status after lock
                        if ($order->status !== 'payment_intent') {
                            return; // Already processed
                        }
                        
                        // Reload payment with lock
                        $payment = Payment::lockForUpdate()->find($payment->id);
                        
                        // Double-check payment status
                        if ($payment->status === 'captured') {
                            return; // Already processed
                        }
                        
                        // Get buyer wallet with lock
                        $buyerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->buyer_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );
                        
                        // Payment confirmed - credit escrow
                        $buyerWallet->on_hold_balance += $order->amount;
                        $buyerWallet->save();

                        // Update order status
                        $oldStatus = $order->status;
                        $order->status = 'escrow_hold';
                        $order->paid_at = now();
                        $order->escrow_hold_at = now();
                        $order->escrow_release_at = now()->addHours(12);
                        $order->save();
                        
                        // Update payment status
                        $payment->status = 'captured';
                        $payment->captured_at = now();
                        $payment->save();
                        
                        // Mark listing as sold
                        $listing = $order->listing;
                        $listingSold = false;
                        if ($listing && $listing->status === 'active') {
                            $listing->status = 'sold';
                            $listing->save();
                            $listingSold = true;
                            
                            // Invalidate listings cache for real-time updates
                            $this->invalidateListingCache($listing->category);
                        }
                        
                        // Audit log
                        AuditHelper::log(
                            'order.payment_confirmed',
                            Order::class,
                            $order->id,
                            ['status' => $oldStatus, 'note' => 'Payment intent - not yet a real order'],
                            [
                                'status' => 'escrow_hold',
                                'paid_at' => $order->paid_at->toIso8601String(),
                                'escrow_hold_at' => $order->escrow_hold_at->toIso8601String(),
                                'note' => 'Order confirmed - payment received via HyperPay webhook',
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
                } else {
                    // Payment already processed, just update status
                    $payment->status = 'captured';
                    $payment->captured_at = $payment->captured_at ?? now();
                    $payment->save();
                }
            } elseif ($resultCode && $this->hyperPayService->isPaymentPending($resultCode)) {
                // Payment pending
                $payment->status = 'initiated';
                $payment->save();
            } else {
                // Payment failed - store failure reason
                $resultDescription = $payload['result']['description'] ?? 'Payment failed';
                $payment->status = 'failed';
                $payment->failure_reason = $resultDescription;
                $payment->save();
                
                Log::info('HyperPay Webhook: Payment marked as failed', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'failure_reason' => $resultDescription,
                    'result_code' => $resultCode,
                ]);
            }

            Log::info('HyperPay Webhook: Successfully processed', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'result_code' => $resultCode,
                'payment_status' => $payment->status,
            ]);

            return response()->json(['message' => MessageHelper::WEBHOOK_PROCESSED]);
        } catch (\Exception $e) {
            Log::error('HyperPay Webhook Error', [
                'payload' => $payload,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json(['message' => 'Webhook processing error'], 500);
        }
    }

    /**
     * Invalidate listings cache for a specific category and all listings
     * This ensures real-time updates when listings are marked as sold
     */
    private function invalidateListingCache(?string $category = null): void
    {
        // Invalidate cache for specific category
        if ($category) {
            Cache::forget('listings_' . md5($category . ''));
        }
        
        // Also invalidate "all listings" cache (no category)
        Cache::forget('listings_' . md5(''));
        
        // Invalidate cache for all categories to ensure consistency
        // This is safe because cache is only used for first page without search
        try {
            $categories = \App\Constants\ListingCategories::all();
            foreach ($categories as $cat) {
                Cache::forget('listings_' . md5($cat . ''));
            }
        } catch (\Exception $e) {
            // If categories helper fails, just log and continue
            Log::warning('Failed to invalidate all category caches', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
