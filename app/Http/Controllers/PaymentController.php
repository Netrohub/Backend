<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\TapPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order is not in pending status'], 400);
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
            'metadata' => [
                'order_id' => $order->id,
                'buyer_id' => $order->buyer_id,
                'seller_id' => $order->seller_id,
            ],
        ];

        $tapResponse = $this->tapService->createCharge($chargeData);

        if (!isset($tapResponse['id'])) {
            return response()->json(['message' => 'Failed to create payment'], 500);
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

        return response()->json([
            'payment' => $payment,
            'redirect_url' => $tapResponse['transaction']['url'] ?? null,
        ]);
    }
}
