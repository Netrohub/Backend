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
        $apiKey = config('services.persona.api_key');
        $templateId = config('services.persona.template_id');
        $environmentId = config('services.persona.environment_id');
        
        // Validate required configuration before assignment
        if (empty($apiKey)) {
            throw new \RuntimeException('PERSONA_API_KEY is not configured. Please set it in your environment variables.');
        }
        if (empty($templateId)) {
            throw new \RuntimeException('PERSONA_TEMPLATE_ID is not configured. Please set it in your environment variables.');
        }
        if (empty($environmentId)) {
            throw new \RuntimeException('PERSONA_ENVIRONMENT_ID is not configured. Please set it in your environment variables.');
        }
        
        // Assign after validation (ensures they're non-null strings)
        $this->apiKey = (string) $apiKey;
        $this->baseUrl = config('services.persona.base_url', 'https://withpersona.com/api/v1');
        $this->templateId = (string) $templateId;
        $this->environmentId = (string) $environmentId;
    }

    public function createInquiry(array $data): array
    {
        // Construct the full request payload
        $payload = array_merge([
            'template-id' => $this->templateId,
            'environment' => $this->environmentId,
        ], $data);

        // Log the full request details for debugging
        Log::info('Persona API Request (Sandbox)', [
            'url' => $this->baseUrl . '/inquiries',
            'template_id' => $this->templateId,
            'environment_id' => $this->environmentId,
            'api_key_prefix' => substr($this->apiKey, 0, 10) . '...', // Log partial key for security
            'payload' => $payload,
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Persona-Version' => '2024-02-05',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->baseUrl . '/inquiries', $payload);

        $responseData = $response->json();
        
        Log::info('Persona Inquiry Response', [
            'status_code' => $response->status(),
            'response' => $responseData,
            'headers' => $response->headers(),
        ]);

        // Check for API errors
        if ($response->failed() || isset($responseData['errors'])) {
            $errorMessage = 'Persona API error';
            if (isset($responseData['errors']) && is_array($responseData['errors']) && !empty($responseData['errors'])) {
                $errorMessage = $responseData['errors'][0]['title'] ?? $responseData['errors'][0]['detail'] ?? 'Persona API error';
            }
            throw new \RuntimeException($errorMessage);
        }

        return $responseData;
    }

    public function retrieveInquiry(string $inquiryId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Persona-Version' => '2024-02-05',
        ])->get($this->baseUrl . '/inquiries/' . $inquiryId);

        return $response->json();
    }

    /**
     * Verify that the template exists in the environment
     * This can help diagnose "Record not found" errors
     */
    public function verifyTemplate(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Persona-Version' => '2024-02-05',
            ])->get($this->baseUrl . '/templates/' . $this->templateId);

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Persona Template Verification Failed', [
                'template_id' => $this->templateId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        $calculatedSignature = hash_hmac('sha256', json_encode($payload), config('services.persona.webhook_secret'));
        return hash_equals($calculatedSignature, $signature);
    }
}

