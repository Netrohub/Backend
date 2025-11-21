<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use App\Services\PersonaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class KycController extends Controller
{
    public function __construct(private PersonaService $personaService) {}

    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        $templateId = config('services.persona.template_id');

        if (!$templateId) {
            Log::error('Persona template ID is not configured');
            return response()->json([
                'message' => 'Identity verification is temporarily unavailable.',
                'error_code' => 'PERSONA_TEMPLATE_UNCONFIGURED',
            ], 500);
        }

        try {
            $inquiryResponse = $this->personaService->createInquiry($templateId, "user_{$user->id}");
        } catch (\Throwable $e) {
            Log::error('Persona inquiry creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to start the verification process.',
                'error_code' => 'PERSONA_INQUIRY_FAILED',
            ], 500);
        }

        $inquiryId = $inquiryResponse['data']['id'] ?? null;
        if (!$inquiryId) {
            Log::error('Persona inquiry created without ID', [
                'user_id' => $user->id,
                'response' => $inquiryResponse,
            ]);

            return response()->json([
                'message' => 'Unexpected response from verification provider.',
                'error_code' => 'PERSONA_INQUIRY_MALFORMED',
            ], 500);
        }

        try {
            $resumeResponse = $this->personaService->resumeInquiry($inquiryId);
        } catch (\Throwable $e) {
            Log::error('Persona resume failed', [
                'user_id' => $user->id,
                'inquiry_id' => $inquiryId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to open the verification interface.',
                'error_code' => 'PERSONA_RESUME_FAILED',
            ], 500);
        }

        $sessionToken = $resumeResponse['meta']['session-token'] ?? null;

        if (!$sessionToken) {
            Log::warning('Persona resume response missing session token', [
                'inquiry_id' => $inquiryId,
                'response' => $resumeResponse,
            ]);
        }

        KycVerification::updateOrCreate(
            ['user_id' => $user->id],
            [
                'persona_inquiry_id' => $inquiryId,
                'status' => 'pending',
                'persona_data' => $inquiryResponse,
            ]
        );

        return response()->json([
            'inquiry_id' => $inquiryId,
            'session_token' => $sessionToken,
            'persona_data' => $resumeResponse['data'] ?? null,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $kyc = $request->user()->kycVerification;

        return response()->json([
            'kyc' => $kyc,
        ]);
    }
}

