<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TapPaymentService
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.tap.secret_key');
        $this->publicKey = config('services.tap.public_key');
        $this->baseUrl = config('services.tap.base_url', 'https://api.tap.company/v2');
    }

    public function createCharge(array $data): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/charges', $data);

        Log::info('Tap Charge Created', [
            'request' => $data,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    public function retrieveCharge(string $chargeId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
        ])->get($this->baseUrl . '/charges/' . $chargeId);

        return $response->json();
    }

    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $calculatedSignature = hash_hmac('sha256', json_encode($payload), config('services.tap.webhook_secret'));
        return hash_equals($calculatedSignature, $signature);
    }

    /**
     * Create a transfer/payout to bank account
     * 
     * @param array $data Transfer data including amount, currency, destination (bank account)
     * @return array Tap API response
     */
    public function createTransfer(array $data): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/transfers', $data);

        Log::info('Tap Transfer Created', [
            'request' => $data,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    /**
     * Retrieve transfer status
     * 
     * @param string $transferId Tap transfer ID
     * @return array Tap API response
     */
    public function retrieveTransfer(string $transferId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
        ])->get($this->baseUrl . '/transfers/' . $transferId);

        return $response->json();
    }
}

