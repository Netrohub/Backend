<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\PaylinkClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\MessageHelper;

class PaymentController extends Controller
{
    // Exchange rate: USD to SAR
    // Update this periodically or use exchange rate API
    const USD_TO_SAR_RATE = 3.75;

    public function __construct(
        private PaylinkClient $paylinkClient
    ) {}

    public function create(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::with('listing')->findOrFail($validated['order_id']);

        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        // Only allow payment for payment_intent status (not yet a real order)
        if ($order->status !== 'payment_intent') {
            return response()->json([
                'message' => 'لا يمكن الدفع لهذا الطلب. الطلب غير صالح أو تم الدفع مسبقاً.',
                'error_code' => 'ORDER_NOT_PAYMENT_INTENT',
            ], 400);
        }

        // SECURITY: Validate order amount matches current listing price (prevent price manipulation)
        if ($order->listing && abs($order->amount - $order->listing->price) > 0.01) {
            return response()->json([
                'message' => 'Order amount does not match listing price. Please create a new order.',
                'error_code' => 'ORDER_AMOUNT_MISMATCH',
            ], 400);
        }

        // SECURITY: Check for existing payment to prevent duplicate payment attempts (idempotency)
        $existingPayment = Payment::where('order_id', $order->id)
            ->whereIn('status', ['initiated', 'authorized', 'captured'])
            ->first();

        if ($existingPayment && $existingPayment->paylink_transaction_no) {
            // Return existing payment info instead of creating duplicate
            $paylinkResponse = $existingPayment->paylink_response ?? [];
            $paymentUrl = $paylinkResponse['url'] ?? null;
            
            // If we don't have payment URL, try to get invoice details
            if (!$paymentUrl && $existingPayment->paylink_transaction_no) {
                try {
                    $invoice = $this->paylinkClient->getInvoice($existingPayment->paylink_transaction_no);
                    $paymentUrl = $invoice['url'] ?? null;
                } catch (\Exception $e) {
                    Log::warning('Failed to retrieve existing invoice URL', [
                        'transaction_no' => $existingPayment->paylink_transaction_no,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return response()->json([
                'message' => 'Payment already initiated for this order',
                'payment' => $existingPayment,
                'paymentUrl' => $paymentUrl,
                'error_code' => 'PAYMENT_ALREADY_EXISTS',
            ], 400);
        }

        // Convert USD amount to SAR for Paylink payment gateway
        // Order amount is stored in USD, but Paylink requires SAR
        $amountUSD = $order->amount;
        $amountSAR = round($amountUSD * self::USD_TO_SAR_RATE, 2);

        Log::info('Payment currency conversion', [
            'order_id' => $order->id,
            'amount_usd' => $amountUSD,
            'amount_sar' => $amountSAR,
            'exchange_rate' => self::USD_TO_SAR_RATE,
        ]);

        // Build Paylink invoice payload
        $invoiceData = [
            'amount' => $amountSAR,
            'currency' => 'SAR',
            'callBackUrl' => config('app.url') . '/payments/paylink/callback?order_id=' . $order->id,
            'clientName' => $request->user()->name,
            'clientEmail' => $request->user()->email,
            'clientMobile' => $request->user()->phone ?? '',
            'note' => 'Order #' . $order->id . ' - ' . ($order->listing->title ?? 'Account Purchase'),
            'orderNumber' => 'NXO-' . $order->id,
            'products' => [
                [
                    'title' => $order->listing->title ?? 'Account Purchase',
                    'price' => $amountSAR,
                    'qty' => 1,
                ],
            ],
        ];

        try {
            // Wrap payment creation in transaction for data consistency
            $result = DB::transaction(function () use ($order, $invoiceData, $request) {
                $paylinkResponse = $this->paylinkClient->createInvoice($invoiceData);

                if (!isset($paylinkResponse['transactionNo'])) {
                    $errorMessage = $paylinkResponse['detail'] ?? $paylinkResponse['title'] ?? MessageHelper::PAYMENT_CREATE_FAILED;
                    throw new \Exception($errorMessage);
                }

                // Create payment record
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'paylink_transaction_no' => $paylinkResponse['transactionNo'],
                    'status' => 'initiated',
                    'amount' => $order->amount,
                    'currency' => 'SAR',
                    'paylink_response' => $paylinkResponse,
                ]);

                // Update order with transaction number
                $order->paylink_transaction_no = $paylinkResponse['transactionNo'];
                $order->save();

                return ['payment' => $payment, 'paylinkResponse' => $paylinkResponse];
            });

            $payment = $result['payment'];
            $paylinkResponse = $result['paylinkResponse'];

            // Get payment URL from invoice details
            $paymentUrl = $paylinkResponse['url'] ?? null;
            
            if (!$paymentUrl) {
                // Try to get invoice details to retrieve payment URL
                try {
                    $invoice = $this->paylinkClient->getInvoice($paylinkResponse['transactionNo']);
                    $paymentUrl = $invoice['url'] ?? null;
                } catch (\Exception $e) {
                    Log::warning('Failed to retrieve payment URL from invoice', [
                        'transaction_no' => $paylinkResponse['transactionNo'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'payment' => $payment,
                'paymentUrl' => $paymentUrl,
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

            Log::error('Payment creation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'message' => $userMessage,
                'error_code' => $errorCode,
                'error' => \App\Helpers\SecurityHelper::getSafeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Handle Paylink payment callback
     * This is called when user returns from Paylink payment page
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback(Request $request)
    {
        $orderId = $request->query('order_id');
        $transactionNo = $request->query('transactionNo');

        if (!$orderId) {
            Log::warning('Paylink callback: Missing order_id', ['query' => $request->query()]);
            return redirect(config('app.frontend_url') . '/orders?error=invalid_callback');
        }

        $order = Order::find($orderId);
        if (!$order) {
            Log::warning('Paylink callback: Order not found', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/orders?error=order_not_found');
        }

        // SECURITY: Never trust callback query params - always re-query invoice status
        try {
            $payment = Payment::where('order_id', $orderId)->first();
            
            if (!$payment || !$payment->paylink_transaction_no) {
                Log::warning('Paylink callback: Payment not found', [
                    'order_id' => $orderId,
                    'transaction_no' => $transactionNo,
                ]);
                return redirect(config('app.frontend_url') . '/order/' . $orderId . '?error=payment_not_found');
            }

            // Get invoice status from Paylink API (never trust callback params)
            $invoice = $this->paylinkClient->getInvoice($payment->paylink_transaction_no);
            
            $orderStatus = $invoice['orderStatus'] ?? null;
            $paidAmount = $invoice['paidAmount'] ?? 0;

            Log::info('Paylink callback: Invoice status', [
                'order_id' => $orderId,
                'transaction_no' => $payment->paylink_transaction_no,
                'order_status' => $orderStatus,
                'paid_amount' => $paidAmount,
            ]);

            // Handle payment status
            if ($orderStatus === 'Paid' && $paidAmount > 0) {
                // Payment successful - webhook should handle the actual processing
                // But we can redirect to success page
                return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
            } elseif ($orderStatus === 'Canceled' || $orderStatus === 'Failed') {
                // Payment failed or cancelled
                return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=failed');
            } else {
                // Payment pending or unknown status
                return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=pending');
            }
        } catch (\Exception $e) {
            Log::error('Paylink callback error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return redirect(config('app.frontend_url') . '/order/' . $orderId . '?error=callback_error');
        }
    }
}
