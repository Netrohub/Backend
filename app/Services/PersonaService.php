<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PersonaService
{
    public function __construct(
        protected ?string $apiKey = null,
        protected string $baseUrl = 'https://api.withpersona.com/api/v1',
    ) {
        // Get API key from config if not provided
        // During Docker build, config might not be available, so handle null gracefully
        $configKey = config('services.persona.api_key');
        // Ensure we never assign null - use empty string as fallback
        $this->apiKey = ($this->apiKey ?? $configKey) ?? '';
        $this->baseUrl = config('services.persona.base_url') ?: $this->baseUrl;

        // Only log if API key is present (avoid errors during build when config might not be available)
        // During Docker build, config might not be loaded, so we allow empty API key
        if (!empty($this->apiKey) && app()->bound('log')) {
            try {
                Log::info('PersonaService initialized', [
                    'api_key_present' => true,
                    'masked_api_key' => $this->maskApiKey($this->apiKey),
                ]);
            } catch (\Throwable $e) {
                // Silently fail during build if Log is not available
            }
        }
    }

    private function maskApiKey(string $key): string
    {
        if (empty($key) || strlen($key) <= 6) {
            return str_repeat('*', max(6, strlen($key)));
        }

        return substr($key, 0, 3) . str_repeat('*', strlen($key) - 6) . substr($key, -3);
    }

    public function createInquiry(string $templateId, string $referenceId): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Persona API key is not configured.');
        }

        $response = Http::withBasicAuth($this->apiKey, '')
            ->post("{$this->baseUrl}/inquiries", [
                'data' => [
                    'type' => 'inquiries',
                    'attributes' => [
                        'reference-id' => $referenceId,
                        'inquiry-template-id' => $templateId,
                    ],
                ],
            ]);

        $response->throw();

        return $response->json();
    }

    public function resumeInquiry(string $inquiryId): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Persona API key is not configured.');
        }

        $response = Http::withBasicAuth($this->apiKey, '')
            ->post("{$this->baseUrl}/inquiries/{$inquiryId}/resume");

        $response->throw();

        return $response->json('data');
    }

    public function getInquiry(string $inquiryId): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Persona API key is not configured.');
        }

        $response = Http::withBasicAuth($this->apiKey, '')
            ->get("{$this->baseUrl}/inquiries/{$inquiryId}");

        $response->throw();

        return $response->json();
    }

    public function verifyWebhookSignature(array $payload, ?string $signature): bool
    {
        $secret = config('services.persona.webhook_secret');
        $signature = $signature ?? '';

        if (!$secret || !$signature) {
            return false;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $expected = hash_hmac('sha256', $payloadJson, $secret);

        return hash_equals($expected, $signature);
    }
}

