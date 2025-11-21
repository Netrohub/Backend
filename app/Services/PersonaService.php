<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PersonaService
{
    public function __construct(
        protected string $apiKey = '',
        protected string $baseUrl = 'https://api.withpersona.com/api/v1',
    ) {
        $this->apiKey = $this->apiKey ?: config('services.persona.api_key');
        $this->baseUrl = config('services.persona.base_url') ?: $this->baseUrl;

        if (empty($this->apiKey)) {
            throw new RuntimeException('Persona API key is not configured.');
        }
    }

    public function createInquiry(string $templateId, string $referenceId): array
    {
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
        $response = Http::withBasicAuth($this->apiKey, '')
            ->post("{$this->baseUrl}/inquiries/{$inquiryId}/resume");

        $response->throw();

        return $response->json('data');
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

