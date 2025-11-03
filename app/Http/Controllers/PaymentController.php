<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\TapPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\MessageHelper;

class PaymentController extends Controller
{
    public function __construct(
        private TapPaymentService $tapService
    ) {}

    public function create(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => MessageHelper::ORDER_NOT_PENDING], 400);
        }

        // Ensure buyer has sufficient balance (if wallet-based payment)
        // For Tap, we'll just create the charge and handle balance on webhook

        // Create Tap charge
        $chargeData = [
            'amount' => $order->amount,
            'currency' => 'SAR',
            'customer' => [
                'first_name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'source' => [
                'id' => 'src_all',
            ],
            'redirect' => [
                'url' => config('app.frontend_url') . '/orders/' . $order->id . '/payment/callback',
            ],
            'post' => [
                'url' => config('app.url') . '/api/v1/webhooks/tap',
            ],
            'metadata' => [
                'order_id' => $order->id,
                'buyer_id' => $order->buyer_id,
                'seller_id' => $order->seller_id,
            ],
        ];

        try {
            // Wrap payment creation in transaction for data consistency
            $result = DB::transaction(function () use ($order, $chargeData, $request) {
                $tapResponse = $this->tapService->createCharge($chargeData);

                if (!isset($tapResponse['id'])) {
                    $errorMessage = $tapResponse['message'] ?? MessageHelper::PAYMENT_CREATE_FAILED;
                    throw new \Exception($errorMessage);
                }

                // Create payment record
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'tap_charge_id' => $tapResponse['id'],
                    'tap_reference' => $tapResponse['reference'] ?? null,
                    'status' => 'initiated',
                    'amount' => $order->amount,
                    'currency' => 'SAR',
                    'tap_response' => $tapResponse,
                ]);

                // Update order
                $order->tap_charge_id = $tapResponse['id'];
                $order->save();

                return ['payment' => $payment, 'tapResponse' => $tapResponse];
            });

            $payment = $result['payment'];
            $tapResponse = $result['tapResponse'];

            return response()->json([
                'payment' => $payment,
                'redirect_url' => $tapResponse['transaction']['url'] ?? null,
            ]);
        } catch (\Exception $e) {
            // Determine error code based on exception message
            $errorCode = 'PAYMENT_CREATE_FAILED';
            $userMessage = MessageHelper::PAYMENT_CREATE_FAILED;
            
            if (str_contains($e->getMessage(), 'network') || str_contains($e->getMessage(), 'timeout')) {
                $errorCode = 'PAYMENT_NETWORK_ERROR';
                $userMessage = 'Unable to connect to payment gateway. Please try again.';
            } elseif (str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'validation')) {
                $errorCode = 'PAYMENT_INVALID_DATA';
                $userMessage = 'Invalid payment data provided. Please check your information.';
            }

            \Illuminate\Support\Facades\Log::error('Payment creation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'message' => $userMessage,
                'error_code' => $errorCode,
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
