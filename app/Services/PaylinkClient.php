<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PaylinkClient
{
    private string $baseUrl;
    private string $apiId;
    private string $secretKey;
    private ?string $token = null;
    private int $tokenExpiry = 0;

    public function __construct()
    {
        $this->baseUrl = config('services.paylink.base_url');
        $this->apiId = config('services.paylink.api_id');
        $this->secretKey = config('services.paylink.secret');
    }

    /**
     * Authenticate and get access token
     * Token is cached for 30 minutes (or 30 hours if persistToken=true)
     * 
     * @param bool $persistToken When true, token valid for 30 hours; when false, 30 minutes
     * @return string Access token
     */
    public function authenticate(bool $persistToken = false): string
    {
        $cacheKey = 'paylink_token_' . ($persistToken ? 'persist' : 'temp');
        
        // Check if we have a valid cached token
        $cached = Cache::get($cacheKey);
        if ($cached && isset($cached['token']) && isset($cached['expires_at'])) {
            if (now()->timestamp < $cached['expires_at']) {
                $this->token = $cached['token'];
                return $this->token;
            }
        }

        Log::info('Paylink: Authenticating', [
            'api_id' => $this->apiId,
            'base_url' => $this->baseUrl,
            'persist_token' => $persistToken,
        ]);

        $response = Http::post($this->baseUrl . '/api/auth', [
            'apiId' => $this->apiId,
            'secretKey' => $this->secretKey,
            'persistToken' => $persistToken,
        ]);

        if (!$response->successful()) {
            $error = $response->json();
            Log::error('Paylink Authentication Failed', [
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \Exception('Paylink authentication failed: ' . ($error['message'] ?? 'Unknown error'));
        }

        $data = $response->json();
        
        if (!isset($data['id_token'])) {
            Log::error('Paylink: No token in response', ['response' => $data]);
            throw new \Exception('Paylink authentication failed: No token received');
        }

        $this->token = $data['id_token'];
        
        // Cache token (30 minutes or 30 hours)
        $expiresIn = $persistToken ? (30 * 60 * 60) : (30 * 60); // seconds
        Cache::put($cacheKey, [
            'token' => $this->token,
            'expires_at' => now()->timestamp + $expiresIn,
        ], now()->addSeconds($expiresIn));

        Log::info('Paylink: Authentication successful', [
            'token_length' => strlen($this->token),
            'expires_in' => $expiresIn,
        ]);

        return $this->token;
    }

    /**
     * Get authorization token (auto-authenticates if needed)
     * 
     * @return string Bearer token
     */
    private function getToken(): string
    {
        if (!$this->token || now()->timestamp >= $this->tokenExpiry) {
            $this->authenticate(false); // Use short-lived token for most operations
        }
        return $this->token;
    }

    /**
     * Make authenticated request to Paylink API
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request body data
     * @return array Response data
     */
    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        $token = $this->getToken();
        
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        
        Log::info('Paylink API Request', [
            'method' => $method,
            'url' => $url,
            'has_data' => !is_null($data),
        ]);

        $http = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ]);

        if ($method === 'GET') {
            $response = $http->get($url);
        } elseif ($method === 'POST') {
            $response = $http->post($url, $data);
        } else {
            throw new \Exception("Unsupported HTTP method: {$method}");
        }

        $responseData = $response->json();

        if (!$response->successful()) {
            Log::error('Paylink API Error', [
                'status' => $response->status(),
                'endpoint' => $endpoint,
                'url' => $url,
                'error' => $responseData,
                'request_data' => $data,
            ]);
            
            $errorMessage = $responseData['detail'] 
                ?? $responseData['title'] 
                ?? $responseData['message'] 
                ?? $responseData['error'] 
                ?? 'Unknown error';
            
            // For 404 errors, provide more context
            if ($response->status() === 404) {
                $errorMessage = 'Invoice not found or endpoint does not exist: ' . $endpoint;
            }
            
            throw new \Exception('Paylink API error: ' . $errorMessage);
        }

        Log::info('Paylink API Success', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
        ]);

        return $responseData;
    }

    /**
     * Create a new invoice
     * 
     * @param array $data Invoice data
     * @return array Invoice response with transactionNo and paymentUrl
     */
    public function createInvoice(array $data): array
    {
        return $this->request('POST', '/api/addInvoice', $data);
    }

    /**
     * Get invoice details by transaction number
     * 
     * Based on Paylink API response, the checkUrl shows:
     * https://restpilot.paylink.sa/api/getInvoice/{transactionNo}
     * So the transaction number should be in the URL path, not body
     * 
     * @param string $transactionNo Transaction number
     * @return array Invoice details
     */
    public function getInvoice(string $transactionNo): array
    {
        // Transaction number should be in URL path, not body
        return $this->request('GET', '/api/getInvoice/' . $transactionNo);
    }

    /**
     * Cancel an invoice
     * 
     * @param string $transactionNo Transaction number
     * @return array Cancel response
     */
    public function cancelInvoice(string $transactionNo): array
    {
        return $this->request('POST', '/api/cancelInvoice', [
            'transactionNo' => $transactionNo,
        ]);
    }
}

