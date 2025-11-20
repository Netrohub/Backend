<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\PaylinkClient;
use App\Notifications\PaymentConfirmed;
use App\Notifications\OrderStatusChanged;
use App\Helpers\AuditHelper;
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

            // Log full response for debugging
            Log::info('Paylink addInvoice response', [
                'order_id' => $order->id,
                'transaction_no' => $paylinkResponse['transactionNo'] ?? null,
                'response_keys' => array_keys($paylinkResponse),
                'response' => $paylinkResponse,
            ]);

            // Get payment URL from addInvoice response
            // Paylink addInvoice response contains the payment URL directly in 'url' field
            // Format: https://paymentpilot.paylink.sa/pay/info/{transactionNo} (sandbox)
            // or: https://paylink.sa/pay/info/{transactionNo} (production)
            $paymentUrl = $paylinkResponse['url'] 
                ?? $paylinkResponse['mobileUrl'] 
                ?? null;
            
            // If URL not found in response, construct it manually based on environment
            if (!$paymentUrl && isset($paylinkResponse['transactionNo'])) {
                $baseUrl = config('services.paylink.base_url', 'https://restpilot.paylink.sa');
                
                // Use paymentpilot for sandbox, paylink.sa for production
                if (str_contains($baseUrl, 'restpilot')) {
                    $paymentUrl = 'https://paymentpilot.paylink.sa/pay/info/' . $paylinkResponse['transactionNo'];
                } else {
                    $paymentUrl = 'https://paylink.sa/pay/info/' . $paylinkResponse['transactionNo'];
                }
                
                Log::info('Constructed Paylink payment URL', [
                    'transaction_no' => $paylinkResponse['transactionNo'],
                    'base_url' => $baseUrl,
                    'constructed_url' => $paymentUrl,
                ]);
            }

            if (!$paymentUrl) {
                Log::error('Payment URL not found in Paylink response', [
                    'transaction_no' => $paylinkResponse['transactionNo'] ?? null,
                    'response_keys' => array_keys($paylinkResponse),
                ]);
            } else {
                Log::info('Payment URL extracted from Paylink response', [
                    'transaction_no' => $paylinkResponse['transactionNo'] ?? null,
                    'payment_url' => $paymentUrl,
                ]);
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

        $order = Order::withTrashed()->find($orderId);
        if (!$order) {
            Log::warning('Paylink callback: Order not found', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/orders?error=order_not_found');
        }

        // Check if order is soft-deleted
        if ($order->trashed()) {
            Log::warning('Paylink callback: Order is soft-deleted', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/orders?error=order_deleted');
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
            // Note: getInvoice might return 404 if invoice was just created
            // In that case, rely on webhook for payment verification
            try {
                // Add small delay to ensure invoice is ready
                usleep(500000); // 0.5 seconds
                
                $invoice = $this->paylinkClient->getInvoice($payment->paylink_transaction_no);
                
                $orderStatus = $invoice['orderStatus'] ?? $invoice['status'] ?? null;
                
                // Check multiple possible fields for paid amount
                $paidAmount = $invoice['paidAmount'] 
                    ?? $invoice['amountPaid'] 
                    ?? $invoice['paymentReceipt']['amount'] 
                    ?? 0;
                
                // If status is Paid but paidAmount is 0, check if paymentReceipt exists
                // PaymentReceipt might indicate payment was successful
                $hasPaymentReceipt = !empty($invoice['paymentReceipt']);
                $invoiceAmount = $invoice['amount'] ?? 0;

                Log::info('Paylink callback: Invoice status', [
                    'order_id' => $orderId,
                    'transaction_no' => $payment->paylink_transaction_no,
                    'order_status' => $orderStatus,
                    'paid_amount' => $paidAmount,
                    'invoice_amount' => $invoiceAmount,
                    'has_payment_receipt' => $hasPaymentReceipt,
                    'payment_receipt' => $invoice['paymentReceipt'] ?? null,
                    'invoice_keys' => array_keys($invoice),
                ]);

                // Handle payment status
                // If orderStatus is "Paid", check for payment receipt or if order status changed
                if ($orderStatus === 'Paid' || $orderStatus === 'paid') {
                    // Check if order was already processed (webhook might have handled it)
                    $order->refresh();
                    if ($order->status === 'escrow_hold' || $order->status === 'completed') {
                        // Order already processed - redirect to success
                        return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
                    }
                    
                    // If paymentReceipt exists, payment is confirmed even if paidAmount is 0
                    // Payment receipt contains: receiptUrl, passcode, paymentMethod, paymentDate, bankCardNumber
                    if ($hasPaymentReceipt) {
                        Log::info('Paylink callback: Payment confirmed via payment receipt', [
                            'order_id' => $orderId,
                            'transaction_no' => $payment->paylink_transaction_no,
                            'payment_method' => $invoice['paymentReceipt']['paymentMethod'] ?? null,
                            'payment_date' => $invoice['paymentReceipt']['paymentDate'] ?? null,
                        ]);
                        
                        // Process payment immediately if order hasn't been processed yet
                        // This ensures order status is updated right away for better UX
                        // Webhook will still process if it arrives later (idempotent)
                        if ($order->status === 'payment_intent') {
                            try {
                                DB::transaction(function () use ($order, $payment, $invoice, $invoiceAmount) {
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
                                    
                                    // Update order status to escrow_hold
                                    $oldStatus = $order->status;
                                    $order->status = 'escrow_hold';
                                    $order->paid_at = now();
                                    $order->escrow_hold_at = now();
                                    $order->escrow_release_at = now()->addHours(12);
                                    $order->save();
                                    
                                    // Update payment status
                                    $payment->status = 'captured';
                                    $payment->captured_at = now();
                                    $payment->paylink_response = array_merge($payment->paylink_response ?? [], $invoice);
                                    $payment->save();
                                    
                                    // Mark listing as sold
                                    $listing = $order->listing;
                                    if ($listing && $listing->status === 'active') {
                                        $listing->status = 'sold';
                                        $listing->save();
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
                                            'note' => 'Order confirmed - payment received via callback',
                                        ],
                                        request()
                                    );
                                    
                                    // Send notifications
                                    $order->buyer->notify(new PaymentConfirmed($order));
                                    $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));
                                    $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));
                                    
                                    // Schedule escrow release job
                                    ReleaseEscrowFunds::dispatch($order->id)
                                        ->delay(now()->addHours(12));
                                    
                                    Log::info('Paylink callback: Payment processed successfully', [
                                        'order_id' => $order->id,
                                        'transaction_no' => $payment->paylink_transaction_no,
                                        'old_status' => $oldStatus,
                                        'new_status' => $order->status,
                                    ]);
                                });
                            } catch (\Exception $e) {
                                Log::error('Paylink callback: Failed to process payment', [
                                    'order_id' => $orderId,
                                    'transaction_no' => $payment->paylink_transaction_no,
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString(),
                                    'note' => 'Webhook will process payment if it arrives',
                                ]);
                                // Continue - webhook will process if callback fails
                            }
                        }
                        
                        // Payment is confirmed - redirect to success page
                        return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
                    }
                    
                    // If status is Paid but no receipt yet, webhook should handle verification
                    // Redirect to processing page - webhook will complete the payment
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=processing');
                } elseif ($orderStatus === 'Canceled' || $orderStatus === 'canceled' || $orderStatus === 'Failed' || $orderStatus === 'failed') {
                    // Payment failed or cancelled
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=failed');
                } elseif ($orderStatus === 'CREATED' || $orderStatus === 'Created' || $orderStatus === 'created') {
                    // Payment still pending
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=pending');
                } else {
                    // Unknown status - redirect to processing and let webhook handle it
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=processing');
                }
            } catch (\Exception $e) {
                // getInvoice failed - might be 404 if invoice was just created or endpoint doesn't exist
                // This is OK - webhook will handle payment verification
                Log::info('Paylink callback: Could not retrieve invoice, webhook will handle verification', [
                    'order_id' => $orderId,
                    'transaction_no' => $payment->paylink_transaction_no,
                    'error' => $e->getMessage(),
                    'note' => 'This is normal for recently created invoices - webhook will verify payment',
                ]);
                
                // Check order status - if already paid, redirect to success
                if ($order->status === 'escrow_hold' || $order->status === 'completed') {
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
                }
                
                // Redirect to order page - user can check status
                // Webhook will update order status when payment is processed
                return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=processing');
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
