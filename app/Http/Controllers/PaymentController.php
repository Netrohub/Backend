<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\PaylinkClient;
use App\Services\HyperPayService;
use App\Services\PayPalService;
use App\Notifications\PaymentConfirmed;
use App\Notifications\OrderStatusChanged;
use App\Helpers\AuditHelper;
use App\Helpers\MadaHelper;
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
        private PaylinkClient $paylinkClient,
        private HyperPayService $hyperPayService,
        private PayPalService $payPalService
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
            // This allows users to retry payment if they had issues on previous page
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
            
            // Return 200 with payment URL to allow user to retry payment
            // Frontend will handle redirecting to payment URL
            return response()->json([
                'message' => 'Payment already initiated for this order. You can continue with the existing payment.',
                'payment' => $existingPayment,
                'paymentUrl' => $paymentUrl,
                'error_code' => 'PAYMENT_ALREADY_EXISTS',
            ], 200);
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
            'clientName' => $request->user()->username ?? $request->user()->name,
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
                // Note: Order amount is in USD, payment record stores USD
                // Paylink gateway receives SAR (converted above), but we store USD
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'user_id' => $order->buyer_id, // Store buyer ID for direct tracking
                    'paylink_transaction_no' => $paylinkResponse['transactionNo'],
                    'status' => 'initiated',
                    'amount' => $order->amount, // USD amount
                    'currency' => 'USD', // Store USD (order currency)
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
                                    
                                    // CRITICAL: Log wallet state before processing to track any anomalies
                                    $walletBefore = [
                                        'available_balance' => $buyerWallet->available_balance,
                                        'on_hold_balance' => $buyerWallet->on_hold_balance,
                                    ];
                                    
                                    // Payment confirmed - credit escrow
                                    // CRITICAL: NEVER add to available_balance for buyer payments - only escrow!
                                    $buyerWallet->on_hold_balance += $order->amount;
                                    $buyerWallet->save();

                                    // Log wallet state after processing for audit trail
                                    Log::info('Paylink Callback: Buyer wallet updated', [
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
                                    $listingSold = false;
                                    if ($listing && $listing->status === 'active') {
                                        $listing->status = 'sold';
                                        $listing->save();
                                        $listingSold = true;
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
                                    if ($listingSold && $listing) {
                                        $order->seller->notify(new \App\Notifications\AccountSold($listing, $order));
                                    }
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

    /**
     * Prepare HyperPay checkout for COPYandPAY widget
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function prepareHyperPayCheckout(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'browserData' => 'nullable|array',
            'browserData.acceptHeader' => 'nullable|string|max:2048',
            'browserData.language' => 'nullable|string|max:8',
            'browserData.screenHeight' => 'nullable|integer',
            'browserData.screenWidth' => 'nullable|integer',
            'browserData.timezone' => 'nullable|integer',
            'browserData.userAgent' => 'nullable|string|max:2048',
            'browserData.javaEnabled' => 'nullable|boolean',
            'browserData.javascriptEnabled' => 'nullable|boolean',
            'browserData.screenColorDepth' => 'nullable|integer',
            'browserData.challengeWindow' => 'nullable|string|max:2',
        ]);

        $order = Order::with('listing')->findOrFail($validated['order_id']);

        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        // Only allow payment for payment_intent status
        if ($order->status !== 'payment_intent') {
            return response()->json([
                'message' => 'لا يمكن الدفع لهذا الطلب. الطلب غير صالح أو تم الدفع مسبقاً.',
                'error_code' => 'ORDER_NOT_PAYMENT_INTENT',
            ], 400);
        }

        // SECURITY: Validate order amount matches current listing price
        if ($order->listing && abs($order->amount - $order->listing->price) > 0.01) {
            return response()->json([
                'message' => 'Order amount does not match listing price. Please create a new order.',
                'error_code' => 'ORDER_AMOUNT_MISMATCH',
            ], 400);
        }

        // SECURITY: Check for existing payment to prevent duplicate payment attempts
        $existingPayment = Payment::where('order_id', $order->id)
            ->whereIn('status', ['initiated', 'authorized', 'captured'])
            ->whereNotNull('hyperpay_checkout_id')
            ->first();

        if ($existingPayment && $existingPayment->hyperpay_checkout_id) {
            // Return existing checkout info
            $widgetScript = $this->hyperPayService->getWidgetScriptUrl($existingPayment->hyperpay_checkout_id);
            return response()->json([
                'message' => 'Payment already initiated for this order. You can continue with the existing payment.',
                'payment' => $existingPayment,
                'checkoutId' => $existingPayment->hyperpay_checkout_id,
                'widgetScriptUrl' => $widgetScript['url'],
                'error_code' => 'PAYMENT_ALREADY_EXISTS',
            ], 200);
        }

        // Prepare checkout data
        $shopperResultUrl = config('app.frontend_url') . '/payments/hyperpay/callback?order_id=' . $order->id;
        
        // Split user name into givenName and surname
        $user = $request->user();
        $userName = $user->name ?? '';
        $nameParts = explode(' ', $userName, 2);
        $givenName = $nameParts[0] ?? '';
        $surname = $nameParts[1] ?? $givenName; // Use givenName as fallback if no surname
        
        // Get customer phone (one of phone/workPhone/mobile is required for 3DS)
        $customerPhone = $user->phone ?? $user->verified_phone ?? null;
        if (!$customerPhone) {
            // Format: +ccc-nnnnnnnn (country code - phone number)
            // Default to Saudi Arabia (+966) if no phone available
            $customerPhone = '+966-500000000'; // Placeholder - should be collected from user
        } else {
            // Format phone number if needed
            $customerPhone = $this->formatPhoneFor3DS($customerPhone);
        }
        
        // Get customer IP address
        $customerIP = $request->ip() ?? $request->header('X-Forwarded-For') ?? $request->header('X-Real-IP') ?? null;
        
        // Get browser data from request (collected on frontend)
        $browserData = $validated['browserData'] ?? [];
        
        // Note: entityId is used in Basic Auth, not as a form parameter
        $checkoutData = [
            'amount' => number_format($order->amount, 2, '.', ''),
            'currency' => 'SAR', // Changed to SAR as per HyperPay requirements
            'paymentType' => 'DB', // Debit (immediate payment)
            'merchantTransactionId' => 'NXO-' . $order->id,
            'shopperResultUrl' => $shopperResultUrl,
            
            // Customer information (required for 3DS)
            'customer.email' => $user->email,
            'customer.givenName' => $givenName,
            'customer.surname' => $surname,
            'customer.phone' => $customerPhone,
            'customer.ip' => $customerIP,
            
            // Billing address (required for 3DS - using defaults for Saudi Arabia)
            'billing.street1' => 'Not Provided', // Required field - default value
            'billing.city' => 'Riyadh', // Required field - default to capital
            'billing.postcode' => '11564', // Required field - default Riyadh postcode
            'billing.country' => 'SA', // Required field - Saudi Arabia (Alpha-2 code)
            'billing.state' => '', // Optional
            
            // Browser data (mandatory for 3DS 2.0)
            'customer.browser.acceptHeader' => $browserData['acceptHeader'] ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'customer.browser.language' => $browserData['language'] ?? 'en',
            'customer.browser.screenHeight' => (string)($browserData['screenHeight'] ?? 1080),
            'customer.browser.screenWidth' => (string)($browserData['screenWidth'] ?? 1920),
            'customer.browser.timezone' => (string)($browserData['timezone'] ?? 0),
            'customer.browser.userAgent' => $browserData['userAgent'] ?? ($request->header('User-Agent') ?? 'Mozilla/5.0'),
            'customer.browser.javaEnabled' => $browserData['javaEnabled'] ?? false ? 'true' : 'false',
            'customer.browser.javascriptEnabled' => $browserData['javascriptEnabled'] ?? true ? 'true' : 'false',
        ];
        
        // Optional browser fields (recommended for better frictionless flow)
        if (isset($browserData['screenColorDepth'])) {
            $checkoutData['customer.browser.screenColorDepth'] = (string)$browserData['screenColorDepth'];
        }
        if (isset($browserData['challengeWindow'])) {
            $checkoutData['customer.browser.challengeWindow'] = $browserData['challengeWindow'];
        }
        
        // Add test mode parameters for test environment only
        $environment = config('services.hyperpay.environment', 'test');
        if ($environment === 'test') {
            $checkoutData['testMode'] = 'EXTERNAL';
            $checkoutData['customParameters[3DS2_enrolled]'] = 'true';
        }

        try {
            $result = DB::transaction(function () use ($order, $checkoutData, $request) {
                // Use Visa/MasterCard entity ID by default (access token is validated for this entity ID)
                // The HyperPay widget can handle MADA payments through the Visa/MasterCard entity ID
                // The widget will show MADA first and handle brand selection automatically
                $checkoutResponse = $this->hyperPayService->prepareCheckout($checkoutData);

                if (!isset($checkoutResponse['id'])) {
                    $errorMessage = $checkoutResponse['result']['description'] 
                        ?? $checkoutResponse['message'] 
                        ?? MessageHelper::PAYMENT_CREATE_FAILED;
                    throw new \Exception($errorMessage);
                }

                // Create payment record
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'user_id' => $order->buyer_id,
                    'hyperpay_checkout_id' => $checkoutResponse['id'],
                    'status' => 'initiated',
                    'amount' => $order->amount,
                    'currency' => 'SAR', // Changed to SAR as per HyperPay requirements
                    'hyperpay_response' => $checkoutResponse,
                ]);

                return ['payment' => $payment, 'checkoutResponse' => $checkoutResponse];
            });

            $payment = $result['payment'];
            $checkoutResponse = $result['checkoutResponse'];
            
            // Extract integrity hash from checkout response (PCI DSS v4.0 compliance)
            $integrity = $checkoutResponse['integrity'] ?? null;
            
            // Build widget script URL
            $baseUrl = rtrim(config('services.hyperpay.base_url'), '/');
            $widgetScriptUrl = "{$baseUrl}/v1/paymentWidgets.js?checkoutId={$checkoutResponse['id']}";

            Log::info('HyperPay checkout prepared', [
                'order_id' => $order->id,
                'checkout_id' => $checkoutResponse['id'],
                'has_integrity' => !empty($integrity),
            ]);

            return response()->json([
                'payment' => $payment,
                'checkoutId' => $checkoutResponse['id'],
                'widgetScriptUrl' => $widgetScriptUrl,
                'integrity' => $integrity,
            ]);
        } catch (\Exception $e) {
            $errorCode = 'PAYMENT_CREATE_FAILED';
            $userMessage = MessageHelper::PAYMENT_CREATE_FAILED;
            
            if (str_contains($e->getMessage(), 'network') || str_contains($e->getMessage(), 'timeout')) {
                $errorCode = 'PAYMENT_NETWORK_ERROR';
                $userMessage = 'Unable to connect to payment gateway. Please try again.';
            } elseif (str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'validation')) {
                $errorCode = 'PAYMENT_INVALID_DATA';
                $userMessage = 'Invalid payment data provided. Please check your information.';
            }

            Log::error('HyperPay checkout preparation failed', [
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
     * Get HyperPay payment status
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHyperPayStatus(Request $request)
    {
        $validated = $request->validate([
            'resourcePath' => 'required|string',
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        // SECURITY: Only order buyer can check payment status
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        try {
            $statusResponse = $this->hyperPayService->getPaymentStatus($validated['resourcePath']);
            
            $resultCode = $statusResponse['result']['code'] ?? null;
            $resultDescription = $statusResponse['result']['description'] ?? 'Unknown status';
            
            // MADA BIN detection: Check if failed payment used a MADA card in credit card flow
            $cardBin = $statusResponse['card']['bin'] ?? null;
            $isMadaCard = MadaHelper::isMadaCard($cardBin);
            $isCreditCardFlow = !empty($statusResponse['paymentType']) && $statusResponse['paymentType'] !== 'DB'; // DB = Debit (MADA)
            
            $isSuccessful = $resultCode && $this->hyperPayService->isPaymentSuccessful($resultCode);
            $isPending = $resultCode && $this->hyperPayService->isPaymentPending($resultCode);
            
            // If payment failed and MADA card was used in credit card flow, show MADA error
            if (!$isSuccessful && $isMadaCard && $isCreditCardFlow) {
                $locale = $request->header('Accept-Language', 'en');
                $locale = str_starts_with($locale, 'ar') ? 'ar' : 'en';
                $madaError = MadaHelper::getMadaErrorMessage($locale);
                
                Log::warning('MADA card used in credit card flow', [
                    'order_id' => $order->id,
                    'card_bin' => $cardBin,
                    'payment_type' => $statusResponse['paymentType'] ?? null,
                ]);
                
                return response()->json([
                    'status' => 'failed',
                    'resultCode' => $resultCode,
                    'resultDescription' => $madaError,
                    'isMadaCard' => true,
                    'response' => $statusResponse,
                ]);
            }

            // Update payment record if successful
            if ($isSuccessful) {
                $payment = Payment::where('order_id', $order->id)
                    ->whereNotNull('hyperpay_checkout_id')
                    ->first();
                
                if ($payment && $payment->status !== 'captured') {
                    DB::transaction(function () use ($payment, $statusResponse, $order) {
                        $payment->status = 'captured';
                        $payment->captured_at = now();
                        $payment->hyperpay_response = array_merge($payment->hyperpay_response ?? [], $statusResponse);
                        $payment->save();

                        // Update order status if still in payment_intent
                        if ($order->status === 'payment_intent') {
                            $buyerWallet = Wallet::lockForUpdate()
                                ->firstOrCreate(
                                    ['user_id' => $order->buyer_id],
                                    ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                                );
                            
                            $buyerWallet->on_hold_balance += $order->amount;
                            $buyerWallet->save();

                            $oldStatus = $order->status;
                            $order->status = 'escrow_hold';
                            $order->paid_at = now();
                            $order->escrow_hold_at = now();
                            $order->escrow_release_at = now()->addHours(12);
                            $order->save();

                            // Mark listing as sold
                            $listing = $order->listing;
                            if ($listing && $listing->status === 'active') {
                                $listing->status = 'sold';
                                $listing->save();
                            }

                            // Send notifications
                            $order->buyer->notify(new PaymentConfirmed($order));
                            if ($listing) {
                                $order->seller->notify(new \App\Notifications\AccountSold($listing, $order));
                            }
                            $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));
                            $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));

                            // Schedule escrow release
                            ReleaseEscrowFunds::dispatch($order->id)
                                ->delay(now()->addHours(12));
                        }
                    });
                }
            }

            return response()->json([
                'status' => $isSuccessful ? 'success' : ($isPending ? 'pending' : 'failed'),
                'resultCode' => $resultCode,
                'resultDescription' => $resultDescription,
                'isMadaCard' => $isMadaCard,
                'response' => $statusResponse,
            ]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $isRateLimit = str_contains($errorMessage, 'rate limit') || str_contains($errorMessage, 'Too many requests');
            
            Log::error('HyperPay get status failed', [
                'order_id' => $order->id,
                'resource_path' => $validated['resourcePath'],
                'error' => $errorMessage,
                'is_rate_limit' => $isRateLimit,
            ]);

            // Return specific error for rate limiting
            if ($isRateLimit) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'rate_limit_exceeded',
                    'message' => 'Too many requests. Please wait a moment and try again.',
                    'retry_after' => 60, // seconds
                ], 429); // 429 Too Many Requests
            }

            return response()->json([
                'message' => 'Failed to get payment status',
                'error' => \App\Helpers\SecurityHelper::getSafeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Handle HyperPay payment callback
     * This is called when user returns from HyperPay payment page
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function hyperPayCallback(Request $request)
    {
        $orderId = $request->query('order_id');
        $resourcePath = $request->query('resourcePath');

        if (!$orderId) {
            Log::warning('HyperPay callback: Missing order_id', ['query' => $request->query()]);
            return redirect(config('app.frontend_url') . '/orders?error=invalid_callback');
        }

        $order = Order::withTrashed()->find($orderId);
        if (!$order) {
            Log::warning('HyperPay callback: Order not found', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/orders?error=order_not_found');
        }

        if ($order->trashed()) {
            Log::warning('HyperPay callback: Order is soft-deleted', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/orders?error=order_deleted');
        }

        // If resourcePath is provided, get payment status
        if ($resourcePath) {
            try {
                $statusResponse = $this->hyperPayService->getPaymentStatus($resourcePath);
                $resultCode = $statusResponse['result']['code'] ?? null;
                
                if ($resultCode && $this->hyperPayService->isPaymentSuccessful($resultCode)) {
                    // Payment successful - redirect to success page
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
                } elseif ($resultCode && $this->hyperPayService->isPaymentPending($resultCode)) {
                    // Payment pending
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=pending');
                } else {
                    // Payment failed
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=failed');
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $isRateLimit = str_contains($errorMessage, 'rate limit') || str_contains($errorMessage, 'Too many requests');
                
                Log::error('HyperPay callback: Failed to get payment status', [
                    'order_id' => $orderId,
                    'resource_path' => $resourcePath,
                    'error' => $errorMessage,
                    'is_rate_limit' => $isRateLimit,
                ]);
                
                // If rate limit error, redirect with specific message
                if ($isRateLimit) {
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=rate_limit&retry_after=60');
                }
                
                // For other errors, redirect to order page - user can check status manually
                return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=error');
            }
        }

        // Check order status - if already paid, redirect to success
        if ($order->status === 'escrow_hold' || $order->status === 'completed') {
            return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
        }

        // Redirect to order page - user can check status
        return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=processing');
    }
    
    /**
     * Format phone number for 3D Secure
     * Format: +ccc-nnnnnnnn (country code - phone number)
     * 
     * @param string $phone Phone number to format
     * @return string Formatted phone number
     */
    private function formatPhoneFor3DS(string $phone): string
    {
        // Remove all non-digit characters except +
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        
        // If phone starts with +, assume it's already formatted
        if (str_starts_with($cleaned, '+')) {
            // Replace first 0 after country code with dash if needed
            // Example: +966501234567 -> +966-501234567
            if (preg_match('/^\+(\d{1,3})(\d+)$/', $cleaned, $matches)) {
                return '+' . $matches[1] . '-' . $matches[2];
            }
            return $cleaned;
        }
        
        // If phone starts with 0, assume it's local format (Saudi Arabia)
        if (str_starts_with($cleaned, '0')) {
            $cleaned = '966' . substr($cleaned, 1); // Remove leading 0, add country code
        }
        
        // If phone doesn't start with country code, assume Saudi Arabia (+966)
        if (!str_starts_with($cleaned, '966') && !str_starts_with($cleaned, '+966')) {
            $cleaned = '966' . $cleaned;
        }
        
        // Format: +ccc-nnnnnnnn
        if (preg_match('/^(\d{1,3})(\d+)$/', $cleaned, $matches)) {
            return '+' . $matches[1] . '-' . $matches[2];
        }
        
        // Fallback: return as-is with + prefix
        return '+' . $cleaned;
    }

    /**
     * Get PayPal client token for JavaScript SDK v6
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPayPalClientToken(Request $request)
    {
        try {
            $clientToken = $this->payPalService->generateClientToken();
            
            return response()->json([
                'clientToken' => $clientToken,
            ]);
        } catch (\Exception $e) {
            Log::error('PayPal client token generation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate client token',
                'error' => \App\Helpers\SecurityHelper::getSafeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Create PayPal order
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPayPalOrder(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::with('listing')->findOrFail($validated['order_id']);

        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        // Only allow payment for payment_intent status
        if ($order->status !== 'payment_intent') {
            return response()->json([
                'message' => 'لا يمكن الدفع لهذا الطلب. الطلب غير صالح أو تم الدفع مسبقاً.',
                'error_code' => 'ORDER_NOT_PAYMENT_INTENT',
            ], 400);
        }

        // SECURITY: Validate order amount matches current listing price
        if ($order->listing && abs($order->amount - $order->listing->price) > 0.01) {
            return response()->json([
                'message' => 'Order amount does not match listing price. Please create a new order.',
                'error_code' => 'ORDER_AMOUNT_MISMATCH',
            ], 400);
        }

        // SECURITY: Check for existing payment to prevent duplicate payment attempts
        $existingPayment = Payment::where('order_id', $order->id)
            ->whereIn('status', ['initiated', 'authorized', 'captured'])
            ->whereNotNull('paypal_order_id')
            ->first();

        if ($existingPayment && $existingPayment->paypal_order_id) {
            // Return existing order info - this is fine for idempotency
            // PayPal SDK can use the existing order ID
            Log::info('PayPal: Returning existing order', [
                'order_id' => $order->id,
                'paypal_order_id' => $existingPayment->paypal_order_id,
            ]);
            return response()->json([
                'payment' => $existingPayment,
                'paypalOrderId' => $existingPayment->paypal_order_id,
                'status' => 'CREATED',
                'error_code' => 'PAYMENT_ALREADY_EXISTS',
            ], 200);
        }

        // Build PayPal order payload using Orders v2 API
        // Reference: https://developer.paypal.com/docs/api/orders/v2/
        // Return URL must point to backend (where the callback route is defined)
        $returnUrl = config('app.url') . '/payments/paypal/callback?order_id=' . $order->id;
        $cancelUrl = config('app.frontend_url') . '/checkout?order_id=' . $order->id . '&payment=cancelled';
        
        // Convert USD to appropriate currency for PayPal (PayPal supports USD)
        $paypalOrderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => 'NXO-' . $order->id,
                    'description' => $order->listing->title ?? 'Account Purchase',
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($order->amount, 2, '.', ''),
                    ],
                ],
            ],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'brand_name' => config('app.name', 'NXOLand'),
                        'locale' => 'en-US',
                        'landing_page' => 'BILLING', // BILLING enables guest checkout (card payment without PayPal account)
                        'shipping_preference' => 'NO_SHIPPING', // Digital goods - no shipping
                        'user_action' => 'PAY_NOW', // Complete payment immediately
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ],
        ];

        try {
            $result = DB::transaction(function () use ($order, $paypalOrderData, $request) {
                $paypalOrder = $this->payPalService->createOrder($paypalOrderData);

                if (!isset($paypalOrder['id'])) {
                    throw new \Exception('PayPal order creation failed: Invalid response');
                }

                // Create payment record
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'user_id' => $order->buyer_id,
                    'paypal_order_id' => $paypalOrder['id'],
                    'status' => 'initiated',
                    'amount' => $order->amount,
                    'currency' => 'USD',
                    'paypal_response' => $paypalOrder,
                ]);

                return ['payment' => $payment, 'paypalOrder' => $paypalOrder];
            });

            $payment = $result['payment'];
            $paypalOrder = $result['paypalOrder'];

            Log::info('PayPal order created', [
                'order_id' => $order->id,
                'paypal_order_id' => $paypalOrder['id'],
                'status' => $paypalOrder['status'] ?? null,
            ]);

            // Find approval URL from links
            // With payment_source, the link rel is 'payer-action' instead of 'approve'
            $approvalUrl = null;
            foreach ($paypalOrder['links'] ?? [] as $link) {
                if ($link['rel'] === 'payer-action' || $link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }

            return response()->json([
                'payment' => $payment,
                'paypalOrderId' => $paypalOrder['id'],
                'approvalUrl' => $approvalUrl,
                'status' => $paypalOrder['status'] ?? null,
            ]);
        } catch (\Exception $e) {
            $errorCode = 'PAYMENT_CREATE_FAILED';
            $userMessage = MessageHelper::PAYMENT_CREATE_FAILED;
            
            if (str_contains($e->getMessage(), 'network') || str_contains($e->getMessage(), 'timeout')) {
                $errorCode = 'PAYMENT_NETWORK_ERROR';
                $userMessage = 'Unable to connect to payment gateway. Please try again.';
            } elseif (str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'validation')) {
                $errorCode = 'PAYMENT_INVALID_DATA';
                $userMessage = 'Invalid payment data provided. Please check your information.';
            }

            Log::error('PayPal order creation failed', [
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
     * Capture PayPal order
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function capturePayPalOrder(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'paypal_order_id' => 'required|string',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        // SECURITY: Only order buyer can capture payment
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        try {
            // Find payment record first - try exact match first, then fallback to any payment for this order
            $payment = Payment::where('order_id', $order->id)
                ->where('paypal_order_id', $validated['paypal_order_id'])
                ->first();
            
            // If not found, try to find any payment for this order with PayPal
            if (!$payment) {
                $payment = Payment::where('order_id', $order->id)
                    ->whereNotNull('paypal_order_id')
                    ->first();
                
                if ($payment && $payment->paypal_order_id !== $validated['paypal_order_id']) {
                    // Update the payment record with the correct PayPal order ID
                    Log::info('PayPal capture: Updating payment record with PayPal order ID', [
                        'payment_id' => $payment->id,
                        'old_paypal_order_id' => $payment->paypal_order_id,
                        'new_paypal_order_id' => $validated['paypal_order_id'],
                    ]);
                    $payment->paypal_order_id = $validated['paypal_order_id'];
                    $payment->save();
                }
            }
            
            // If still not found, create a new payment record (shouldn't happen, but handle gracefully)
            if (!$payment) {
                Log::warning('PayPal capture: Payment record not found, creating new one', [
                    'order_id' => $order->id,
                    'paypal_order_id' => $validated['paypal_order_id'],
                ]);
                
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'user_id' => $order->buyer_id,
                    'paypal_order_id' => $validated['paypal_order_id'],
                    'status' => 'initiated',
                    'amount' => $order->amount,
                    'currency' => 'USD',
                ]);
            }
            
            $captureResponse = $this->payPalService->captureOrder($validated['paypal_order_id']);
            
            $status = $captureResponse['status'] ?? null;
            $isSuccessful = $status && $this->payPalService->isOrderSuccessful($status);

            if ($isSuccessful) {
                // Process payment if not already captured
                if ($payment->status !== 'captured') {
                    DB::transaction(function () use ($payment, $captureResponse, $order) {
                        // Reload with lock to prevent race conditions
                        $payment = Payment::lockForUpdate()->find($payment->id);
                        $order = Order::lockForUpdate()->find($order->id);
                        
                        // Double-check payment status after lock
                        if ($payment->status === 'captured') {
                            Log::info('PayPal capture: Payment already captured', [
                                'payment_id' => $payment->id,
                                'order_id' => $order->id,
                            ]);
                            return; // Already processed
                        }
                        
                        $payment->status = 'captured';
                        $payment->captured_at = now();
                        $payment->paypal_response = array_merge($payment->paypal_response ?? [], $captureResponse);
                        $payment->save();

                        // Update order status if still in payment_intent
                        if ($order->status === 'payment_intent') {
                            $buyerWallet = Wallet::lockForUpdate()
                                ->firstOrCreate(
                                    ['user_id' => $order->buyer_id],
                                    ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                                );
                            
                            $buyerWallet->on_hold_balance += $order->amount;
                            $buyerWallet->save();

                            $oldStatus = $order->status;
                            $order->status = 'escrow_hold';
                            $order->paid_at = now();
                            $order->escrow_hold_at = now();
                            $order->escrow_release_at = now()->addHours(12);
                            $order->save();

                            // Mark listing as sold
                            $listing = $order->listing;
                            if ($listing && $listing->status === 'active') {
                                $listing->status = 'sold';
                                $listing->save();
                            }

                            // Send notifications
                            $order->buyer->notify(new PaymentConfirmed($order));
                            if ($listing) {
                                $order->seller->notify(new \App\Notifications\AccountSold($listing, $order));
                            }
                            $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));
                            $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));

                            // Schedule escrow release
                            ReleaseEscrowFunds::dispatch($order->id)
                                ->delay(now()->addHours(12));
                        }
                    });
                }
            }

            return response()->json([
                'status' => $isSuccessful ? 'success' : ($this->payPalService->isOrderPending($status) ? 'pending' : 'failed'),
                'paypalOrderId' => $validated['paypal_order_id'],
                'message' => $isSuccessful ? 'Payment captured successfully' : ($this->payPalService->isOrderPending($status) ? 'Payment is pending' : 'Payment capture failed'),
                'response' => $captureResponse,
            ]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $isPayPalError = str_contains($errorMessage, 'PayPal');
            
            Log::error('PayPal order capture failed', [
                'order_id' => $order->id,
                'paypal_order_id' => $validated['paypal_order_id'],
                'error' => $errorMessage,
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            // Provide user-friendly error message
            $userMessage = $isPayPalError 
                ? 'Payment processing failed. Please try again or contact support if payment was deducted.'
                : 'Failed to capture payment. Please try again.';

            return response()->json([
                'message' => $userMessage,
                'error_code' => 'PAYMENT_CAPTURE_FAILED',
                'error' => \App\Helpers\SecurityHelper::getSafeErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Handle PayPal payment callback
     * This is called when user returns from PayPal payment page after approval
     * Reference: https://developer.paypal.com/docs/api/orders/v2/
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function payPalCallback(Request $request)
    {
        $orderId = $request->query('order_id');
        $token = $request->query('token'); // PayPal order ID (token parameter)

        if (!$orderId) {
            Log::warning('PayPal callback: Missing order_id', ['query' => $request->query()]);
            return redirect(config('app.frontend_url') . '/orders?error=invalid_callback');
        }

        // Check if user cancelled
        if ($request->query('cancel') === 'true' || $request->has('cancel')) {
            Log::info('PayPal callback: User cancelled payment', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/checkout?order_id=' . $orderId . '&payment=cancelled');
        }

        $order = Order::withTrashed()->find($orderId);
        if (!$order) {
            Log::warning('PayPal callback: Order not found', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/orders?error=order_not_found');
        }

        if ($order->trashed()) {
            Log::warning('PayPal callback: Order is soft-deleted', ['order_id' => $orderId]);
            return redirect(config('app.frontend_url') . '/orders?error=order_deleted');
        }

        // Check if order is already paid
        if ($order->status === 'escrow_hold' || $order->status === 'completed') {
            return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
        }

        // With Orders v2 API, token is the PayPal order ID
        // Find payment by PayPal order ID
        $paypalOrderId = $token;
        if (!$paypalOrderId) {
            // Try to get from payment record if token not in URL
            $payment = Payment::where('order_id', $orderId)
                ->whereNotNull('paypal_order_id')
                ->first();
            $paypalOrderId = $payment->paypal_order_id ?? null;
        }

        if ($paypalOrderId) {
            // Find payment by PayPal order ID
            $payment = Payment::where('order_id', $orderId)
                ->where('paypal_order_id', $paypalOrderId)
                ->first();

            if ($payment) {
                // Try to capture the order
                try {
                    $captureResponse = $this->payPalService->captureOrder($paypalOrderId);
                    $status = $captureResponse['status'] ?? null;
                    
                    if ($this->payPalService->isOrderSuccessful($status)) {
                        // Process payment (same logic as capturePayPalOrder)
                        if ($payment->status !== 'captured' && $order->status === 'payment_intent') {
                            DB::transaction(function () use ($order, $payment, $captureResponse) {
                                $order = Order::lockForUpdate()->find($order->id);
                                if ($order->status !== 'payment_intent') {
                                    return; // Already processed
                                }
                                
                                $payment = Payment::lockForUpdate()->find($payment->id);
                                if ($payment->status === 'captured') {
                                    return; // Already processed
                                }
                                
                                $buyerWallet = Wallet::lockForUpdate()
                                    ->firstOrCreate(
                                        ['user_id' => $order->buyer_id],
                                        ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                                    );
                                
                                $buyerWallet->on_hold_balance += $order->amount;
                                $buyerWallet->save();

                                $oldStatus = $order->status;
                                $order->status = 'escrow_hold';
                                $order->paid_at = now();
                                $order->escrow_hold_at = now();
                                $order->escrow_release_at = now()->addHours(12);
                                $order->save();

                                $payment->status = 'captured';
                                $payment->captured_at = now();
                                $payment->paypal_response = array_merge($payment->paypal_response ?? [], $captureResponse);
                                $payment->save();

                                $listing = $order->listing;
                                if ($listing && $listing->status === 'active') {
                                    $listing->status = 'sold';
                                    $listing->save();
                                }

                                $order->buyer->notify(new PaymentConfirmed($order));
                                if ($listing) {
                                    $order->seller->notify(new \App\Notifications\AccountSold($listing, $order));
                                }
                                $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));
                                $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'escrow_hold'));

                                ReleaseEscrowFunds::dispatch($order->id)
                                    ->delay(now()->addHours(12));
                            });
                        }
                        
                        return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=success');
                    } else {
                        return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=failed');
                    }
                } catch (\Exception $e) {
                    Log::error('PayPal callback: Failed to capture order', [
                        'order_id' => $orderId,
                        'token' => $token,
                        'error' => $e->getMessage(),
                    ]);
                    return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=error');
                }
            }
        }

        // Check if payment was cancelled
        if ($request->query('cancel') === 'true' || !$token) {
            return redirect(config('app.frontend_url') . '/checkout?order_id=' . $orderId . '&payment=cancelled');
        }

        // Redirect to order page - user can check status
        return redirect(config('app.frontend_url') . '/order/' . $orderId . '?payment=processing');
    }
}
