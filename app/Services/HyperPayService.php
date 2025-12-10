<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HyperPayService
{
    private string $baseUrl;
    private string $entityId; // Visa/MasterCard entity ID
    private string $entityIdMada; // MADA entity ID
    private string $accessToken;
    private string $environment; // 'test' or 'live'

    public function __construct()
    {
        $baseUrl = config('services.hyperpay.base_url') ?? 'https://eu-test.oppwa.com';
        $entityId = config('services.hyperpay.entity_id') ?? '';
        $entityIdMada = config('services.hyperpay.entity_id_mada') ?? '';
        $accessToken = config('services.hyperpay.access_token') ?? '';
        $environment = config('services.hyperpay.environment') ?? 'test';
        
        // Validate required configuration before assignment
        if (empty($entityId) || $entityId === 'your_entity_id_here') {
            throw new \RuntimeException('HyperPay entity_id is not configured. Please set HYPERPAY_ENTITY_ID in your .env file with your actual HyperPay entity ID.');
        }
        
        if (empty($entityIdMada) || $entityIdMada === 'your_entity_id_mada_here') {
            throw new \RuntimeException('HyperPay entity_id_mada is not configured. Please set HYPERPAY_ENTITY_ID_MADA in your .env file with your actual MADA entity ID.');
        }
        
        if (empty($accessToken) || $accessToken === 'your_access_token_here') {
            throw new \RuntimeException('HyperPay access_token is not configured. Please set HYPERPAY_ACCESS_TOKEN in your .env file with your actual HyperPay access token.');
        }
        
        if (empty($baseUrl)) {
            throw new \RuntimeException('HyperPay base_url is not configured. Please set HYPERPAY_BASE_URL in your .env file.');
        }
        
        // Assign after validation (ensures non-null values)
        // Remove trailing slash from base URL to prevent double slashes in API calls
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->entityId = trim($entityId); // Remove any whitespace
        $this->entityIdMada = trim($entityIdMada); // Remove any whitespace
        $this->accessToken = trim($accessToken); // Remove any whitespace
        $this->environment = $environment;
        
        // Log configuration (without sensitive data)
        Log::info('HyperPayService initialized', [
            'base_url' => $this->baseUrl,
            'entity_id' => $this->entityId,
            'entity_id_mada' => $this->entityIdMada,
            'environment' => $this->environment,
            'access_token_length' => strlen($this->accessToken),
            'access_token_starts_with' => substr($this->accessToken, 0, 10) . '...',
        ]);
    }
    
    /**
     * Get entity ID for a specific payment brand
     * 
     * Returns MADA entity ID for MADA payments, Visa/MasterCard entity ID for others.
     * 
     * @param string|null $brand Payment brand: 'MADA', 'VISA', 'MASTER', etc.
     * @return string Entity ID to use
     */
    private function getEntityId(?string $brand = null): string
    {
        // Use MADA entity ID for MADA payments, Visa/MasterCard entity ID for Visa/MasterCard
        $brandUpper = strtoupper($brand ?? '');
        if ($brandUpper === 'MADA') {
            return $this->entityIdMada;
        }
        // For VISA, MASTERCARD, or any other brand, use Visa/MasterCard entity ID
        // (MASTERCARD is the full name, but both use the same entity ID)
        return $this->entityId;
    }

    /**
     * Prepare checkout for COPYandPAY widget
     * 
     * Now supports payment method selection before checkout creation.
     * This ensures we use the correct entity ID:
     * - MADA entity ID for MADA payments
     * - Visa/MasterCard entity ID for Visa/MasterCard payments
     * 
     * @param array $data Checkout data including amount, currency, etc.
     * @param string|null $brand Payment brand: 'MADA', 'VISA', 'MASTERCARD', etc.
     *                          If provided, uses the appropriate entity ID for that brand.
     *                          If null, defaults to MADA entity ID (primary in Saudi Arabia).
     * @return array Response with checkout ID and integrity hash
     */
    public function prepareCheckout(array $data, ?string $brand = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v1/checkouts';
        
        // Use the appropriate entity ID based on selected payment method
        // MADA requires MADA entity ID, Visa/MasterCard use Visa/MasterCard entity ID
        $entityId = $this->getEntityId($brand);
        
        // Format amount for test server: remove decimals (xx.00 -> xx)
        if ($this->environment === 'test' && isset($data['amount'])) {
            $amount = floatval($data['amount']);
            $data['amount'] = number_format($amount, 0, '.', ''); // Remove decimals for test server
        }
        
        // Add integrity=true for PCI DSS v4.0 compliance
        $data['integrity'] = 'true';
        
        // According to HyperPay API Reference:
        // - Authentication: Authorization Bearer <access-token> header
        // - entityId: Sent as form parameter (Conditional)
        // - Content-Type: application/x-www-form-urlencoded; charset=UTF-8
        
        Log::info('HyperPay: Preparing checkout', [
            'url' => $url,
            'entity_id' => $entityId,
            'brand' => $brand ?? 'MADA (default)',
            'entity_id_type' => $brand === 'MADA' ? 'MADA' : 'Visa/MasterCard',
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'integrity' => true,
            'access_token_length' => strlen($this->accessToken),
            'auth_method' => 'Bearer Token (entityId as form parameter)',
        ]);

        // Make HTTP request with Bearer Token Authentication
        // According to HyperPay API Reference: "All requests are authenticated against an Authorization Bearer header with an access token"
        // Entity ID is sent as a form parameter (entityId)
        // Access Token is sent in Authorization header as Bearer token
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
        ])
            ->asForm()
            ->post($url, array_merge($data, ['entityId' => $entityId]));

        $responseData = $response->json();

        if (!$response->successful()) {
            $errorCode = $responseData['result']['code'] ?? null;
            $errorDescription = $responseData['result']['description'] ?? 'Unknown error';
            
            Log::error('HyperPay: Checkout preparation failed', [
                'status' => $response->status(),
                'error_code' => $errorCode,
                'error_description' => $errorDescription,
                'error' => $responseData,
                'entity_id_used' => $entityId,
                'entity_id_length' => strlen($entityId),
                'access_token_length' => strlen($this->accessToken),
                'access_token_starts_with' => substr($this->accessToken, 0, 10) . '...',
                'base_url' => $this->baseUrl,
                'environment' => $this->environment,
                'url' => $url,
                'request_data_keys' => array_keys($data), // Log keys only, not values
            ]);
            
            // Provide more specific error message for authentication errors
            if ($errorCode === '800.900.300' || $response->status() === 401) {
                $errorMessage = 'HyperPay authentication failed. Please verify: '
                    . '1) HYPERPAY_ENTITY_ID (' . $entityId . ') matches the entity ID in your HyperPay dashboard, '
                    . '2) HYPERPAY_ACCESS_TOKEN is correct and matches this entity ID (token length: ' . strlen($this->accessToken) . '), '
                    . '3) You are using the correct environment (' . $this->environment . ' - base URL: ' . $this->baseUrl . '), '
                    . '4) The access token has not expired (HyperPay tokens can expire), '
                    . '5) There are no extra spaces or newlines in your .env file values. '
                    . 'Error: ' . $errorDescription;
            } else {
                $errorMessage = $errorDescription;
            }
            
            throw new \Exception('HyperPay checkout preparation failed: ' . $errorMessage);
        }

        Log::info('HyperPay: Checkout prepared successfully', [
            'checkout_id' => $responseData['id'] ?? null,
            'integrity' => $responseData['integrity'] ?? null,
        ]);

        return $responseData;
    }

    /**
     * Get payment status by resource path
     * 
     * HyperPay rate limiting: Only 2 requests per minute allowed for payment status checks
     * We cache responses for 30 seconds to respect this limit
     * 
     * @param string $resourcePath Resource path from redirect (e.g., /v1/checkouts/{checkoutId}/payment)
     * @return array Payment status response
     */
    public function getPaymentStatus(string $resourcePath, ?string $brand = null): array
    {
        // Ensure resourcePath starts with /
        $resourcePath = '/' . ltrim($resourcePath, '/');
        $url = rtrim($this->baseUrl, '/') . $resourcePath;
        
        // Extract ID from resourcePath for cache key
        // Examples: /v1/checkouts/{checkoutId}/payment or /v1/payments/{paymentId}
        preg_match('#/(checkouts|payments)/([^/]+)#', $resourcePath, $matches);
        $cacheKey = 'hyperpay_status_' . ($matches[2] ?? md5($resourcePath));
        
        // Check cache first (30 seconds TTL to respect 2 requests/minute limit)
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::info('HyperPay: Using cached payment status', [
                'resource_path' => $resourcePath,
                'cache_key' => $cacheKey,
            ]);
            return $cached;
        }
        
        // Get appropriate entity ID based on payment brand
        // For COPYandPAY widget (brand = null), use MADA entity ID to match checkout creation
        // If Visa/MasterCard payment is detected, we'll retry with Visa/MasterCard entity ID
        $entityId = $brand === null ? $this->entityIdMada : $this->getEntityId($brand);
        
        Log::info('HyperPay: Getting payment status', [
            'url' => $url,
            'resource_path' => $resourcePath,
            'entity_id' => $entityId,
            'brand' => $brand,
            'cache_key' => $cacheKey,
        ]);

        // Use Bearer token authentication as per API reference
        // For GET requests, entityId may be sent as query parameter if required
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
        ])
            ->get($url, ['entityId' => $entityId]);

        $responseData = $response->json();
        $resultCode = $responseData['result']['code'] ?? null;
        $errorDescription = $responseData['result']['description'] ?? 'Unknown error';
        $paymentBrand = $responseData['paymentBrand'] ?? null;
        $paymentType = $responseData['paymentType'] ?? null;

        // Check if we need to retry with different entity ID
        // This can happen when:
        // 1. Using MADA entity ID but payment is Visa/MasterCard (retry with Visa/MasterCard entity ID)
        // 2. Using Visa/MasterCard entity ID but payment is MADA (retry with MADA entity ID)
        $shouldRetryWithMada = false;
        $shouldRetryWithVisaMaster = false;
        
        if ($brand === null) {
            // Check if payment brand is MADA but we used Visa/MasterCard entity ID
            if ($paymentBrand === 'MADA' && $entityId === $this->entityId) {
                $shouldRetryWithMada = true;
            }
            // Check if payment brand is Visa/MasterCard but we used MADA entity ID
            elseif (in_array($paymentBrand, ['VISA', 'MASTER', 'MASTERCARD']) && $entityId === $this->entityIdMada) {
                $shouldRetryWithVisaMaster = true;
            }
            // Check result code for currency/subtype errors (600.200.500)
            elseif ($resultCode && str_starts_with($resultCode, '600.200')) {
                // If we're using MADA entity ID and get currency error, might be Visa/MasterCard payment
                if ($entityId === $this->entityIdMada) {
                    $shouldRetryWithVisaMaster = true;
                }
                // If we're using Visa/MasterCard entity ID and get currency error, might be MADA payment
                elseif ($entityId === $this->entityId) {
                    $shouldRetryWithMada = true;
                }
            }
            // Check error description for currency/subtype errors
            elseif (
                str_contains($errorDescription, 'currency') || 
                str_contains($errorDescription, 'sub type') ||
                str_contains($errorDescription, 'country or brand') ||
                str_contains($errorDescription, 'not configured')
            ) {
                // If we're using MADA entity ID and get currency error, might be Visa/MasterCard payment
                if ($entityId === $this->entityIdMada) {
                    $shouldRetryWithVisaMaster = true;
                }
                // If we're using Visa/MasterCard entity ID and get currency error, might be MADA payment
                elseif ($entityId === $this->entityId) {
                    $shouldRetryWithMada = true;
                }
            }
        }

        if (!$response->successful()) {
            // Check for rate limiting error (800.120.100)
            if ($resultCode === '800.120.100') {
                Log::warning('HyperPay: Rate limit exceeded', [
                    'resource_path' => $resourcePath,
                    'error' => $errorDescription,
                ]);
                
                // Try to return cached response if available (even if expired)
                $staleCache = Cache::get($cacheKey);
                if ($staleCache !== null) {
                    Log::info('HyperPay: Returning stale cached response due to rate limit', [
                        'resource_path' => $resourcePath,
                    ]);
                    return $staleCache;
                }
                
                throw new \Exception('HyperPay rate limit exceeded. Too many requests. Please try again later.');
            }
            
            // If we get a currency/subtype error and brand is null (COPYandPAY widget),
            // try with appropriate entity ID based on payment brand
            if ($shouldRetryWithMada) {
                Log::info('HyperPay: Currency/subtype error detected (HTTP error), retrying with MADA entity ID', [
                    'resource_path' => $resourcePath,
                    'error' => $errorDescription,
                    'result_code' => $resultCode,
                    'payment_brand' => $paymentBrand,
                ]);
                
                // Retry with MADA entity ID
                $madaEntityId = $this->entityIdMada;
                $retryResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ])
                    ->get($url, ['entityId' => $madaEntityId]);
                
                $retryResponseData = $retryResponse->json();
                
                if ($retryResponse->successful()) {
                    Log::info('HyperPay: Payment status retrieved with MADA entity ID (after HTTP error)', [
                        'result_code' => $retryResponseData['result']['code'] ?? null,
                        'payment_type' => $retryResponseData['paymentType'] ?? null,
                    ]);
                    
                    // Cache successful response
                    Cache::put($cacheKey, $retryResponseData, 30);
                    return $retryResponseData;
                }
            } elseif ($shouldRetryWithVisaMaster) {
                Log::info('HyperPay: Currency/subtype error detected (HTTP error), retrying with Visa/MasterCard entity ID', [
                    'resource_path' => $resourcePath,
                    'error' => $errorDescription,
                    'result_code' => $resultCode,
                    'payment_brand' => $paymentBrand,
                ]);
                
                // Retry with Visa/MasterCard entity ID
                $visaMasterEntityId = $this->entityId;
                $retryResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ])
                    ->get($url, ['entityId' => $visaMasterEntityId]);
                
                $retryResponseData = $retryResponse->json();
                
                if ($retryResponse->successful()) {
                    Log::info('HyperPay: Payment status retrieved with Visa/MasterCard entity ID (after HTTP error)', [
                        'result_code' => $retryResponseData['result']['code'] ?? null,
                        'payment_type' => $retryResponseData['paymentType'] ?? null,
                    ]);
                    
                    // Cache successful response
                    Cache::put($cacheKey, $retryResponseData, 30);
                    return $retryResponseData;
                }
            }
            
            Log::error('HyperPay: Get payment status failed', [
                'status' => $response->status(),
                'result_code' => $resultCode,
                'error' => $responseData,
                'resource_path' => $resourcePath,
                'entity_id_used' => $entityId,
            ]);
            
            $errorMessage = $errorDescription;
            throw new \Exception('HyperPay get payment status failed: ' . $errorMessage);
        }

        // HTTP request successful, but check if result code indicates currency/subtype error
        // Retry with appropriate entity ID based on payment brand
        if ($shouldRetryWithMada) {
            Log::info('HyperPay: Currency/subtype error detected in result code, retrying with MADA entity ID', [
                'resource_path' => $resourcePath,
                'result_code' => $resultCode,
                'error_description' => $errorDescription,
                'payment_brand' => $paymentBrand,
                'payment_type' => $paymentType,
                'entity_id_used' => $entityId,
            ]);
            
            // Retry with MADA entity ID
            $madaEntityId = $this->entityIdMada;
            $retryResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])
                ->get($url, ['entityId' => $madaEntityId]);
            
            $retryResponseData = $retryResponse->json();
            
            if ($retryResponse->successful()) {
                $retryResultCode = $retryResponseData['result']['code'] ?? null;
                Log::info('HyperPay: Payment status retrieved with MADA entity ID (after result code error)', [
                    'result_code' => $retryResultCode,
                    'payment_type' => $retryResponseData['paymentType'] ?? null,
                    'payment_brand' => $retryResponseData['paymentBrand'] ?? null,
                ]);
                
                // Cache successful response
                Cache::put($cacheKey, $retryResponseData, 30);
                return $retryResponseData;
            } else {
                // If retry also fails, log and return original response
                Log::warning('HyperPay: Retry with MADA entity ID also failed', [
                    'retry_status' => $retryResponse->status(),
                    'retry_result_code' => $retryResponseData['result']['code'] ?? null,
                ]);
            }
        } elseif ($shouldRetryWithVisaMaster) {
            Log::info('HyperPay: Currency/subtype error detected in result code, retrying with Visa/MasterCard entity ID', [
                'resource_path' => $resourcePath,
                'result_code' => $resultCode,
                'error_description' => $errorDescription,
                'payment_brand' => $paymentBrand,
                'payment_type' => $paymentType,
                'entity_id_used' => $entityId,
            ]);
            
            // Retry with Visa/MasterCard entity ID
            $visaMasterEntityId = $this->entityId;
            $retryResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
            ])
                ->get($url, ['entityId' => $visaMasterEntityId]);
            
            $retryResponseData = $retryResponse->json();
            
            if ($retryResponse->successful()) {
                $retryResultCode = $retryResponseData['result']['code'] ?? null;
                Log::info('HyperPay: Payment status retrieved with Visa/MasterCard entity ID (after result code error)', [
                    'result_code' => $retryResultCode,
                    'payment_type' => $retryResponseData['paymentType'] ?? null,
                    'payment_brand' => $retryResponseData['paymentBrand'] ?? null,
                ]);
                
                // Cache successful response
                Cache::put($cacheKey, $retryResponseData, 30);
                return $retryResponseData;
            } else {
                // If retry also fails, log and return original response
                Log::warning('HyperPay: Retry with Visa/MasterCard entity ID also failed', [
                    'retry_status' => $retryResponse->status(),
                    'retry_result_code' => $retryResponseData['result']['code'] ?? null,
                ]);
            }
        }

        // Cache response (even if it contains an error result code)
        Cache::put($cacheKey, $responseData, 30);

        Log::info('HyperPay: Payment status retrieved', [
            'result_code' => $resultCode,
            'payment_type' => $paymentType,
            'payment_brand' => $paymentBrand,
            'cached' => true,
        ]);

        return $responseData;
    }

    /**
     * Get payment status by checkout ID
     * 
     * @param string $checkoutId Checkout ID
     * @return array Payment status response
     */
    public function getPaymentStatusByCheckoutId(string $checkoutId): array
    {
        $resourcePath = "/v1/checkouts/{$checkoutId}/payment";
        return $this->getPaymentStatus($resourcePath);
    }

    /**
     * Verify webhook signature (if configured)
     * 
     * @param array $payload Webhook payload
     * @param string $signature Signature from header
     * @return bool True if signature is valid
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $webhookSecret = config('services.hyperpay.webhook_secret');
        
        if (!$webhookSecret) {
            // If no secret configured, skip verification (not recommended for production)
            Log::warning('HyperPay: Webhook secret not configured, skipping signature verification');
            return true;
        }

        // HyperPay typically uses HMAC-SHA256 for webhook signatures
        $expectedSignature = hash_hmac('sha256', json_encode($payload), $webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check if payment result code indicates success
     * 
     * @param string $resultCode Result code from HyperPay
     * @return bool True if payment was successful
     */
    public function isPaymentSuccessful(string $resultCode): bool
    {
        // HyperPay result codes:
        // 000.000.000 - Transaction succeeded
        // 000.000.100 - Transaction succeeded (review)
        // 000.100.110 - Transaction succeeded (initial)
        // 000.100.111 - Transaction succeeded (waiting for external result)
        // 000.100.112 - Transaction succeeded (waiting for external result)
        // 000.200.000 - Transaction succeeded (external result)
        // 000.300.000 - Transaction succeeded (external result)
        // 000.400.000 - Transaction succeeded (external result)
        // 000.400.110 - Transaction succeeded (external result)
        
        $successCodes = [
            '000.000.000',
            '000.000.100',
            '000.100.110',
            '000.100.111',
            '000.100.112',
            '000.200.000',
            '000.300.000',
            '000.400.000',
            '000.400.110',
        ];

        return in_array($resultCode, $successCodes);
    }

    /**
     * Check if payment result code indicates pending
     * 
     * @param string $resultCode Result code from HyperPay
     * @return bool True if payment is pending
     */
    public function isPaymentPending(string $resultCode): bool
    {
        // Pending codes typically start with 000.200 or 000.300
        return str_starts_with($resultCode, '000.200') || str_starts_with($resultCode, '000.300');
    }

    /**
     * Check if result code indicates resource not found or invalid parameter
     * This can happen when checking payment status before payment is completed
     * 
     * @param string $resultCode Result code from HyperPay
     * @return bool True if resource not found or invalid parameter
     */
    public function isResourceNotFound(string $resultCode): bool
    {
        // 200.300.404 - Resource not found / Invalid or missing parameter
        // This can occur when checking payment status before payment is completed
        $notFoundCodes = [
            '200.300.404',
            '200.300.400',
        ];
        
        return in_array($resultCode, $notFoundCodes) || str_starts_with($resultCode, '200.300.404');
    }

    /**
     * Get widget script URL with integrity hash
     * 
     * @param string $checkoutId Checkout ID
     * @return array Array with 'url' and 'integrity' hash
     */
    public function getWidgetScriptUrl(string $checkoutId): array
    {
        $baseUrl = rtrim($this->baseUrl, '/');
        $scriptUrl = "{$baseUrl}/v1/paymentWidgets.js?checkoutId={$checkoutId}";
        
        // Note: In production, you should fetch the integrity hash from HyperPay
        // For now, we'll return the URL and let the frontend handle it
        return [
            'url' => $scriptUrl,
            'integrity' => null, // Should be fetched from HyperPay API or configured
        ];
    }

    /**
     * Verify credentials by making a simple API call
     * This can be used to test if Entity ID and Access Token are correct
     * 
     * @return array Verification result
     */
    public function verifyCredentials(): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v1/checkouts';
        
        // Make a minimal request to test authentication
        // Using a very small amount for testing
        $testData = [
            'amount' => '0.01',
            'currency' => 'SAR',
            'paymentType' => 'DB',
            'merchantTransactionId' => 'TEST-' . time(),
        ];
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            ])
                ->asForm()
                ->post($url, array_merge($testData, ['entityId' => $this->entityId]));
            
            $responseData = $response->json();
            
            if ($response->successful()) {
                return [
                    'valid' => true,
                    'message' => 'Credentials are valid',
                    'checkout_id' => $responseData['id'] ?? null,
                ];
            } else {
                $errorCode = $responseData['result']['code'] ?? null;
                $errorDescription = $responseData['result']['description'] ?? 'Unknown error';
                
                return [
                    'valid' => false,
                    'message' => 'Credentials are invalid',
                    'error_code' => $errorCode,
                    'error_description' => $errorDescription,
                    'status' => $response->status(),
                ];
            }
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Failed to verify credentials: ' . $e->getMessage(),
            ];
        }
    }
}

