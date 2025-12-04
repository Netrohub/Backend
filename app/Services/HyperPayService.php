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
        
        if (empty($accessToken) || $accessToken === 'your_access_token_here') {
            throw new \RuntimeException('HyperPay access_token is not configured. Please set HYPERPAY_ACCESS_TOKEN in your .env file with your actual HyperPay access token.');
        }
        
        if (empty($baseUrl)) {
            throw new \RuntimeException('HyperPay base_url is not configured. Please set HYPERPAY_BASE_URL in your .env file.');
        }
        
        // Assign after validation (ensures non-null values)
        $this->baseUrl = $baseUrl;
        $this->entityId = $entityId;
        $this->entityIdMada = $entityIdMada ?: $entityId; // Fallback to main entity ID if MADA not configured
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
     * @param string|null $brand Payment brand: 'MADA', 'VISA', 'MASTER', etc.
     * @return string Entity ID to use
     */
    private function getEntityId(?string $brand = null): string
    {
        // Use Visa/MasterCard entity ID by default
        // The access token provided is validated for Visa/MasterCard entity ID
        // The HyperPay widget can handle MADA payments through the Visa/MasterCard entity ID
        // Only use MADA entity ID if explicitly requested AND we have a separate MADA access token
        if ($brand === 'MADA' && !empty($this->entityIdMada) && $this->entityIdMada !== $this->entityId) {
            // Only use MADA entity ID if it's different from main entity ID
            // Note: This requires a separate MADA access token to work properly
            return $this->entityIdMada;
        }
        
        // Default to Visa/MasterCard entity ID (works with provided access token)
        return $this->entityId;
    }

    /**
     * Prepare checkout for COPYandPAY widget
     * 
     * @param array $data Checkout data including amount, currency, etc.
     * @param string|null $brand Payment brand: 'MADA', 'VISA', 'MASTER', etc. Defaults to MADA
     * @return array Response with checkout ID and integrity hash
     */
    public function prepareCheckout(array $data, ?string $brand = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v1/checkouts';
        
        // Get appropriate entity ID based on payment brand (defaults to MADA)
        $entityId = $this->getEntityId($brand);
        
        // Add integrity=true for PCI DSS v4.0 compliance
        $data['integrity'] = 'true';
        
        Log::info('HyperPay: Preparing checkout', [
            'url' => $url,
            'entity_id' => $entityId,
            'brand' => $brand ?? 'Visa/MasterCard (default)',
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'integrity' => true,
            'access_token_length' => strlen($this->accessToken),
        ]);

        // Make HTTP request with Basic Auth
        // HyperPay uses entityId as username and accessToken as password
        $response = Http::withBasicAuth($entityId, $this->accessToken)
            ->asForm()
            ->post($url, $data);

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
                'url' => $url,
                'request_data_keys' => array_keys($data), // Log keys only, not values
            ]);
            
            // Provide more specific error message for authentication errors
            if ($errorCode === '800.900.300' || $response->status() === 401) {
                $errorMessage = 'HyperPay authentication failed. Please verify: '
                    . '1) HYPERPAY_ENTITY_ID matches the entity ID in your HyperPay dashboard, '
                    . '2) HYPERPAY_ACCESS_TOKEN is correct and matches this entity ID, '
                    . '3) You are using the correct environment (test vs production). '
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

        $response = Http::withBasicAuth($entityId, $this->accessToken)
            ->get($url);

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
}

