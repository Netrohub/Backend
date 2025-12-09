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
        // Use MADA entity ID for MADA payments, Visa/MasterCard for others
        if (strtoupper($brand ?? '') === 'MADA') {
            return $this->entityIdMada;
        }
        return $this->entityId;
    }

    /**
     * Prepare checkout for COPYandPAY widget
     * 
     * For COPYandPAY, we use Visa/MasterCard entity ID by default.
     * The widget will show all payment methods (MADA, VISA, MASTER) and handle brand selection.
     * 
     * @param array $data Checkout data including amount, currency, etc.
     * @param string|null $brand Payment brand: 'MADA', 'VISA', 'MASTER', etc. (not used for COPYandPAY - widget handles all brands)
     * @return array Response with checkout ID and integrity hash
     */
    public function prepareCheckout(array $data, ?string $brand = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v1/checkouts';
        
        // For COPYandPAY widget, use Visa/MasterCard entity ID
        // The widget will display all payment methods (MADA, VISA, MASTER) and handle brand selection automatically
        $entityId = $this->entityId;
        
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
            'brand' => 'COPYandPAY (all brands via widget)',
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
        
        // Get appropriate entity ID based on payment brand (defaults to MADA)
        $entityId = $this->getEntityId($brand);
        
        Log::info('HyperPay: Getting payment status', [
            'url' => $url,
            'resource_path' => $resourcePath,
            'entity_id' => $entityId,
            'cache_key' => $cacheKey,
        ]);

        // Use Bearer token authentication as per API reference
        // For GET requests, entityId may be sent as query parameter if required
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
        ])
            ->get($url, ['entityId' => $entityId]);

        $responseData = $response->json();

        if (!$response->successful()) {
            $resultCode = $responseData['result']['code'] ?? null;
            $errorDescription = $responseData['result']['description'] ?? 'Unknown error';
            
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
            
            Log::error('HyperPay: Get payment status failed', [
                'status' => $response->status(),
                'result_code' => $resultCode,
                'error' => $responseData,
                'resource_path' => $resourcePath,
            ]);
            
            $errorMessage = $errorDescription;
            throw new \Exception('HyperPay get payment status failed: ' . $errorMessage);
        }

        // Cache successful response for 30 seconds (allows max 2 requests per minute)
        Cache::put($cacheKey, $responseData, 30);

        Log::info('HyperPay: Payment status retrieved', [
            'result_code' => $responseData['result']['code'] ?? null,
            'payment_type' => $responseData['paymentType'] ?? null,
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

