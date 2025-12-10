<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\HyperPayService;
use App\Notifications\PaymentConfirmed;
use App\Notifications\OrderStatusChanged;
use App\Helpers\AuditHelper;
use App\Helpers\MadaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\MessageHelper;

class PaymentController extends Controller
{
    public function __construct(
        private HyperPayService $hyperPayService
    ) {}

    /**
     * Legacy create endpoint - redirects to HyperPay
     * Kept for backward compatibility, but only HyperPay is supported now
     */
    public function create(Request $request)
    {
        // Redirect to HyperPay checkout preparation
        return $this->prepareHyperPayCheckout($request);
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
            'amount' => number_format($order->amount, 2, '.', ''), // Will be formatted to whole number for test server in HyperPayService
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
            
            // NOTE: Billing address and browser data are NOT sent here for COPYandPAY widget
            // The COPYandPAY widget automatically collects and sends:
            // - Browser data (acceptHeader, language, screenHeight, screenWidth, timezone, userAgent, javaEnabled, javascriptEnabled, screenColorDepth, challengeWindow)
            // - Billing address (if required by the payment method)
            // - shopperResultUrl (from checkout creation)
            // Sending these during checkout preparation causes "was already set and cannot be overwritten" errors
            // For COPYandPAY widget, only send: amount, currency, paymentType, merchantTransactionId, shopperResultUrl, customer info, and customParameters
        ];
        
        // Calculate account age and purchase history for better frictionless flow
        $accountCreatedAt = $user->created_at;
        $accountAgeDays = $accountCreatedAt ? now()->diffInDays($accountCreatedAt) : 0;
        
        // Determine account age indicator
        $accountAgeIndicator = '01'; // No account (guest check-out) - default
        if ($accountAgeDays > 60) {
            $accountAgeIndicator = '05'; // More than 60 days
        } elseif ($accountAgeDays >= 30) {
            $accountAgeIndicator = '04'; // 30-60 days
        } elseif ($accountAgeDays > 0) {
            $accountAgeIndicator = '03'; // Less than 30 days
        } elseif ($accountCreatedAt && $accountCreatedAt->isToday()) {
            $accountAgeIndicator = '02'; // Created during this transaction
        }
        
        // Calculate purchase history
        $completedOrders = Order::where('buyer_id', $user->id)
            ->where('status', 'completed')
            ->get();
        
        $purchaseCountLast6Months = $completedOrders
            ->filter(fn($order) => $order->created_at->isAfter(now()->subMonths(6)))
            ->count();
        
        $transactionsLast24Hours = Order::where('buyer_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();
        
        $transactionsLastYear = Order::where('buyer_id', $user->id)
            ->where('created_at', '>=', now()->subYear())
            ->count();
        
        // Determine authentication method (user is logged in, so they authenticated)
        // 02 = Login using own credentials (most common)
        $reqAuthMethod = '02';
        
        // Add recommended customParameters for better frictionless flow
        $checkoutData['customParameters[AccountAgeIndicator]'] = $accountAgeIndicator;
        if ($accountCreatedAt) {
            $checkoutData['customParameters[AccountDate]'] = $accountCreatedAt->format('Ymd');
        }
        $checkoutData['customParameters[AccountPurchaseCount]'] = (string)$purchaseCountLast6Months;
        $checkoutData['customParameters[AccountDayTransactions]'] = (string)$transactionsLast24Hours;
        $checkoutData['customParameters[AccountYearTransactions]'] = (string)$transactionsLastYear;
        $checkoutData['customParameters[ReqAuthMethod]'] = $reqAuthMethod;
        $checkoutData['customParameters[ReqAuthTimestamp]'] = now()->format('YmdHi'); // YYYYMMDDHHMM format
        
        // Transaction type: 01 = Goods/Service Purchase
        $checkoutData['customParameters[TransactionType]'] = '01';
        
        // Delivery timeframe: 01 = Electronic Delivery (digital goods)
        $checkoutData['customParameters[DeliveryTimeframe]'] = '01';
        
        // Shipping indicator: 05 = Digital goods
        $checkoutData['customParameters[ShipIndicator]'] = '05';
        
        // Note: testMode and 3DS2_enrolled are not valid HyperPay parameters
        // Remove them to prevent format errors

        try {
            $result = DB::transaction(function () use ($order, $checkoutData, $request) {
                // Use COPYandPAY widget - it will show all payment methods (MADA, VISA, MASTER)
                // The widget uses Visa/MasterCard entity ID and handles all brands automatically
                // MADA will be shown first as per Saudi Payments requirements
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
            // For COPYandPAY widget, try Visa/MasterCard entity ID first
            // The service will auto-retry with MADA entity ID if currency/subtype error occurs
            // Also try to detect MADA from payment type (DB = Debit = MADA)
            $statusResponse = $this->hyperPayService->getPaymentStatus($validated['resourcePath'], null);
            
            $resultCode = $statusResponse['result']['code'] ?? null;
            $resultDescription = $statusResponse['result']['description'] ?? 'Unknown status';
            
            // MADA detection: Check payment type and card BIN
            $paymentType = $statusResponse['paymentType'] ?? null;
            $cardBin = $statusResponse['card']['bin'] ?? null;
            $isMadaPayment = ($paymentType === 'DB'); // DB = Debit (MADA)
            $isMadaCard = MadaHelper::isMadaCard($cardBin);
            $isCreditCardFlow = !empty($paymentType) && $paymentType !== 'DB'; // DB = Debit (MADA)
            
            $isSuccessful = $resultCode && $this->hyperPayService->isPaymentSuccessful($resultCode);
            $isPending = $resultCode && $this->hyperPayService->isPaymentPending($resultCode);
            
            // Log detailed error for debugging
            if (!$isSuccessful && !$isPending) {
                Log::warning('HyperPay: Payment failed', [
                    'order_id' => $order->id,
                    'result_code' => $resultCode,
                    'result_description' => $resultDescription,
                    'resource_path' => $validated['resourcePath'],
                    'response' => $statusResponse,
                ]);
            }
            
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
                        // Reload order with lock to prevent race conditions
                        $order = Order::lockForUpdate()->with('listing')->find($order->id);
                        
                        // Reload payment with lock
                        $payment = Payment::lockForUpdate()->find($payment->id);
                        
                        // Double-check payment status after lock
                        if ($payment->status === 'captured') {
                            return; // Already processed
                        }
                        
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

                            // CRITICAL: Mark listing as sold - must happen after order status update
                            $listing = $order->listing;
                            $listingSold = false;
                            if ($listing && $listing->status === 'active') {
                                $listing->status = 'sold';
                                $listing->save();
                                $listingSold = true;
                                
                                // Invalidate listings cache for real-time updates
                                $this->invalidateListingCache($listing->category);
                                
                                Log::info('HyperPay: Listing marked as sold', [
                                    'order_id' => $order->id,
                                    'listing_id' => $listing->id,
                                    'listing_status' => $listing->status,
                                    'category' => $listing->category,
                                ]);
                            } else {
                                Log::warning('HyperPay: Could not mark listing as sold', [
                                    'order_id' => $order->id,
                                    'listing_id' => $listing?->id,
                                    'listing_status' => $listing?->status,
                                    'has_listing' => !is_null($listing),
                                ]);
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
                                    'listing_sold' => $listingSold,
                                    'note' => 'Order confirmed - payment received via HyperPay status check',
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

                            // Schedule escrow release
                            ReleaseEscrowFunds::dispatch($order->id)
                                ->delay(now()->addHours(12));
                        }
                    });
                }
            }

            // Update payment record if failed
            if (!$isSuccessful) {
                $payment = Payment::where('order_id', $order->id)
                    ->whereNotNull('hyperpay_checkout_id')
                    ->first();
                
                if ($payment) {
                    $payment->status = 'failed';
                    $payment->failure_reason = $resultDescription;
                    $payment->hyperpay_response = array_merge($payment->hyperpay_response ?? [], $statusResponse);
                    $payment->save();
                    
                    Log::info('HyperPay: Payment marked as failed', [
                        'order_id' => $order->id,
                        'payment_id' => $payment->id,
                        'failure_reason' => $resultDescription,
                        'result_code' => $resultCode,
                    ]);
                }
            }
            
            // Only return success or failed - no pending for immediate payment methods
            $finalStatus = $isSuccessful ? 'success' : 'failed';
            
            return response()->json([
                'status' => $finalStatus,
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
                // Try to get payment status (will auto-retry with MADA entity ID if needed)
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
