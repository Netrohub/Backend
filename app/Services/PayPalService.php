<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $environment; // 'sandbox' or 'live'
    private ?string $accessToken = null;
    private ?int $accessTokenExpiresAt = null;

    public function __construct()
    {
        $this->baseUrl = config('services.paypal.base_url');
        $this->clientId = config('services.paypal.client_id') ?? '';
        $this->clientSecret = config('services.paypal.client_secret') ?? '';
        $this->environment = config('services.paypal.environment', 'sandbox');

        if (empty($this->clientId) || $this->clientId === 'your_client_id_here') {
            throw new \RuntimeException('PayPal client_id is not configured. Please set PAYPAL_CLIENT_ID in your .env file.');
        }

        if (empty($this->clientSecret) || $this->clientSecret === 'your_client_secret_here') {
            throw new \RuntimeException('PayPal client_secret is not configured. Please set PAYPAL_CLIENT_SECRET in your .env file.');
        }

        if (empty($this->baseUrl)) {
            // Set default base URL based on environment
            $this->baseUrl = $this->environment === 'live' 
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';
        }

        Log::info('PayPalService initialized', [
            'base_url' => $this->baseUrl,
            'environment' => $this->environment,
            'client_id_length' => strlen($this->clientId),
        ]);
    }

    /**
     * Get access token for PayPal API
     * 
     * @return string Access token
     */
    private function getAccessToken(): string
    {
        // Return cached token if still valid (with 5 minute buffer)
        if ($this->accessToken && $this->accessTokenExpiresAt && time() < ($this->accessTokenExpiresAt - 300)) {
            return $this->accessToken;
        }

        $url = rtrim($this->baseUrl, '/') . '/v1/oauth2/token';

        Log::info('PayPal: Requesting access token', [
            'url' => $url,
        ]);

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($url, [
                'grant_type' => 'client_credentials',
            ]);

        if (!$response->successful()) {
            $error = $response->json();
            Log::error('PayPal: Failed to get access token', [
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \Exception('PayPal authentication failed: ' . ($error['error_description'] ?? 'Unknown error'));
        }

        $data = $response->json();
        $this->accessToken = $data['access_token'];
        $this->accessTokenExpiresAt = time() + ($data['expires_in'] ?? 3600);

        Log::info('PayPal: Access token obtained', [
            'expires_in' => $data['expires_in'] ?? null,
        ]);

        return $this->accessToken;
    }

    /**
     * Create a PayPal order
     * 
     * @param array $orderData Order data including amount, currency, etc.
     * @return array PayPal order response
     */
    public function createOrder(array $orderData): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v2/checkout/orders';
        $accessToken = $this->getAccessToken();

        Log::info('PayPal: Creating order', [
            'url' => $url,
            'amount' => $orderData['purchase_units'][0]['amount']['value'] ?? null,
            'currency' => $orderData['purchase_units'][0]['amount']['currency_code'] ?? null,
        ]);

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Prefer' => 'return=representation',
            ])
            ->post($url, $orderData);

        $responseData = $response->json();

        if (!$response->successful()) {
            Log::error('PayPal: Order creation failed', [
                'status' => $response->status(),
                'error' => $responseData,
            ]);

            $errorMessage = $responseData['message'] 
                ?? $responseData['error_description'] 
                ?? 'Unknown error';
            
            throw new \Exception('PayPal order creation failed: ' . $errorMessage);
        }

        Log::info('PayPal: Order created successfully', [
            'order_id' => $responseData['id'] ?? null,
            'status' => $responseData['status'] ?? null,
        ]);

        return $responseData;
    }

    /**
     * Capture a PayPal order
     * 
     * @param string $orderId PayPal order ID
     * @return array Capture response
     */
    public function captureOrder(string $orderId): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v2/checkout/orders/' . $orderId . '/capture';
        $accessToken = $this->getAccessToken();

        Log::info('PayPal: Capturing order', [
            'url' => $url,
            'order_id' => $orderId,
        ]);

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Prefer' => 'return=representation',
            ])
            ->post($url);

        $responseData = $response->json();

        if (!$response->successful()) {
            Log::error('PayPal: Order capture failed', [
                'status' => $response->status(),
                'order_id' => $orderId,
                'error' => $responseData,
            ]);

            $errorMessage = $responseData['message'] 
                ?? $responseData['error_description'] 
                ?? 'Unknown error';
            
            throw new \Exception('PayPal order capture failed: ' . $errorMessage);
        }

        Log::info('PayPal: Order captured successfully', [
            'order_id' => $orderId,
            'status' => $responseData['status'] ?? null,
        ]);

        return $responseData;
    }

    /**
     * Get order details
     * 
     * @param string $orderId PayPal order ID
     * @return array Order details
     */
    public function getOrder(string $orderId): array
    {
        $url = rtrim($this->baseUrl, '/') . '/v2/checkout/orders/' . $orderId;
        $accessToken = $this->getAccessToken();

        Log::info('PayPal: Getting order details', [
            'url' => $url,
            'order_id' => $orderId,
        ]);

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->get($url);

        $responseData = $response->json();

        if (!$response->successful()) {
            Log::error('PayPal: Failed to get order', [
                'status' => $response->status(),
                'order_id' => $orderId,
                'error' => $responseData,
            ]);

            $errorMessage = $responseData['message'] 
                ?? $responseData['error_description'] 
                ?? 'Unknown error';
            
            throw new \Exception('PayPal get order failed: ' . $errorMessage);
        }

        return $responseData;
    }

    /**
     * Verify webhook signature
     * 
     * @param array $headers Request headers
     * @param string $body Raw request body
     * @return bool True if signature is valid
     */
    public function verifyWebhookSignature(array $headers, string $body): bool
    {
        $webhookId = config('services.paypal.webhook_id');
        
        if (!$webhookId) {
            Log::warning('PayPal: Webhook ID not configured, skipping signature verification');
            return true; // Skip verification if not configured
        }

        // PayPal webhook verification requires calling their API
        // For now, we'll do basic validation
        // In production, implement full webhook signature verification
        $authAlgo = $headers['PAYPAL-AUTH-ALGO'] ?? null;
        $certUrl = $headers['PAYPAL-CERT-URL'] ?? null;
        $transmissionId = $headers['PAYPAL-TRANSMISSION-ID'] ?? null;
        $transmissionSig = $headers['PAYPAL-TRANSMISSION-SIG'] ?? null;
        $transmissionTime = $headers['PAYPAL-TRANSMISSION-TIME'] ?? null;

        if (!$authAlgo || !$certUrl || !$transmissionId || !$transmissionSig || !$transmissionTime) {
            Log::warning('PayPal: Missing webhook signature headers');
            return false;
        }

        // TODO: Implement full webhook signature verification
        // This requires calling PayPal's webhook verification API
        // For now, we'll trust the webhook if all headers are present
        
        return true;
    }

    /**
     * Check if order status indicates success
     * 
     * @param string $status Order status
     * @return bool True if order is successful
     */
    public function isOrderSuccessful(string $status): bool
    {
        return in_array(strtoupper($status), ['COMPLETED', 'APPROVED']);
    }

    /**
     * Check if order status indicates pending
     * 
     * @param string $status Order status
     * @return bool True if order is pending
     */
    public function isOrderPending(string $status): bool
    {
        return in_array(strtoupper($status), ['PENDING', 'CREATED']);
    }
}

