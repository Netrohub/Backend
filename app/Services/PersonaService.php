<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PersonaService
{
    private string $apiKey;
    private string $baseUrl;
    private string $templateId;
    private string $environmentId;

    public function __construct()
    {
        $this->apiKey = config('services.persona.api_key');
        $this->baseUrl = config('services.persona.base_url', 'https://withpersona.com/api/v1');
        $this->templateId = config('services.persona.template_id');
        $this->environmentId = config('services.persona.environment_id');
    }

    public function createInquiry(array $data): array
    {
        $response = Http::withHeaders([
            'Key' => $this->apiKey,
            'Persona-Version' => '2024-02-05',
        ])->post($this->baseUrl . '/inquiries', array_merge([
            'template-id' => $this->templateId,
            'environment' => $this->environmentId,
        ], $data));

        Log::info('Persona Inquiry Created', [
            'request' => $data,
            'response' => $response->json(),
        ]);

        return $response->json();
    }

    public function retrieveInquiry(string $inquiryId): array
    {
        $response = Http::withHeaders([
            'Key' => $this->apiKey,
            'Persona-Version' => '2024-02-05',
        ])->get($this->baseUrl . '/inquiries/' . $inquiryId);

        return $response->json();
    }

    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $calculatedSignature = hash_hmac('sha256', json_encode($payload), config('services.persona.webhook_secret'));
        return hash_equals($calculatedSignature, $signature);
    }
}

