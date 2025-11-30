<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HyperPayService
{
    private string $baseUrl;
    private string $entityId;
    private string $accessToken;
    private string $environment; // 'test' or 'live'

    public function __construct()
    {
        $baseUrl = config('services.hyperpay.base_url') ?? 'https://eu-test.oppwa.com';
        $entityId = config('services.hyperpay.entity_id') ?? '';
        $accessToken = config('services.hyperpay.access_token') ?? '';
        $environment = config('services.hyperpay.environment') ?? 'test';
        
        // Validate required configuration before assignment
        if (empty($entityId)) {
            throw new \RuntimeException('HyperPay entity_id is not configured. Please set HYPERPAY_ENTITY_ID in your .env file.');
        }
        
        if (empty($accessToken)) {
            throw new \RuntimeException('HyperPay access_token is not configured. Please set HYPERPAY_ACCESS_TOKEN in your .env file.');
        }
        
        if (empty($baseUrl)) {
            throw new \RuntimeException('HyperPay base_url is not configured. Please set HYPERPAY_BASE_URL in your .env file.');
        }
        
        // Assign after validation (ensures non-null values)
        $this->baseUrl = $baseUrl;
        $this->entityId = $entityId;
        $this->accessToken = $accessToken;
        $this->environment = $environment;
    }

    /**
     * Prepare checkout for COPYandPAY widget
     * 
     * @param array $data Checkout data including amount, currency, etc.
     * @return array Response with checkout ID
     */
    public function prepareCheckout(array $data): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v1/checkouts';
        
        Log::info('HyperPay: Preparing checkout', [
            'url' => $url,
            'entity_id' => $this->entityId,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
        ]);

        $response = Http::withBasicAuth($this->entityId, $this->accessToken)
            ->asForm()
            ->post($url, $data);

        $responseData = $response->json();

        if (!$response->successful()) {
            Log::error('HyperPay: Checkout preparation failed', [
                'status' => $response->status(),
                'error' => $responseData,
                'request_data' => $data,
            ]);
            
            $errorMessage = $responseData['result']['description'] 
                ?? $responseData['message'] 
                ?? $responseData['error'] 
                ?? 'Unknown error';
            
            throw new \Exception('HyperPay checkout preparation failed: ' . $errorMessage);
        }

        Log::info('HyperPay: Checkout prepared successfully', [
            'checkout_id' => $responseData['id'] ?? null,
        ]);

        return $responseData;
    }

    /**
     * Get payment status by resource path
     * 
     * @param string $resourcePath Resource path from redirect (e.g., /v1/checkouts/{checkoutId}/payment)
     * @return array Payment status response
     */
    public function getPaymentStatus(string $resourcePath): array
    {
        // Ensure resourcePath starts with /
        $resourcePath = '/' . ltrim($resourcePath, '/');
        $url = rtrim($this->baseUrl, '/') . $resourcePath;
        
        Log::info('HyperPay: Getting payment status', [
            'url' => $url,
            'resource_path' => $resourcePath,
        ]);

        $response = Http::withBasicAuth($this->entityId, $this->accessToken)
            ->get($url);

        $responseData = $response->json();

        if (!$response->successful()) {
            Log::error('HyperPay: Get payment status failed', [
                'status' => $response->status(),
                'error' => $responseData,
                'resource_path' => $resourcePath,
            ]);
            
            $errorMessage = $responseData['result']['description'] 
                ?? $responseData['message'] 
                ?? $responseData['error'] 
                ?? 'Unknown error';
            
            throw new \Exception('HyperPay get payment status failed: ' . $errorMessage);
        }

        Log::info('HyperPay: Payment status retrieved', [
            'result_code' => $responseData['result']['code'] ?? null,
            'payment_type' => $responseData['paymentType'] ?? null,
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

