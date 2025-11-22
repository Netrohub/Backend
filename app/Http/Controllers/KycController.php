<?php

namespace App\Http\Controllers;

use App\Services\PersonaKycHandler;
use App\Services\PersonaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KycController extends Controller
{
    public function __construct(
        private PersonaService $personaService,
        private PersonaKycHandler $kycHandler
    ) {}

    public function status(Request $request): JsonResponse
    {
        $kyc = $request->user()->kycVerification;

        return response()->json([
            'kyc' => $kyc,
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();

        // Backend guard: prevent re-verification if already verified
        if ($user->is_verified || $user->kyc_status === 'verified') {
            Log::info('KYC complete: User already verified', [
                'user_id' => $user->id,
                'is_verified' => $user->is_verified,
                'kyc_status' => $user->kyc_status,
            ]);
            return response()->json([
                'message' => 'KYC already verified',
                'kyc' => $user->kycVerification,
            ], 400);
        }

        $validated = $request->validate([
            'inquiryId' => ['required', 'string', 'regex:/^inq_[a-zA-Z0-9]+$/'],
            'status' => 'required|string',
            'userId' => 'required|integer',
        ]);

        if ((int) $validated['userId'] !== $user->id) {
            Log::warning('KYC complete: User mismatch', [
                'user_id' => $user->id,
                'provided_user_id' => $validated['userId'],
            ]);
            return response()->json([
                'message' => 'User mismatch',
            ], 403);
        }

        try {
            // Fetch latest inquiry data from Persona API
            $payload = $this->personaService->getInquiry($validated['inquiryId']);
            
            Log::info('KYC complete: Fetched inquiry from Persona', [
                'user_id' => $user->id,
                'inquiry_id' => $validated['inquiryId'],
                'has_payload' => !empty($payload),
            ]);
        } catch (\Throwable $e) {
            Log::warning('KYC complete: Unable to fetch inquiry details', [
                'user_id' => $user->id,
                'inquiry_id' => $validated['inquiryId'],
                'error' => $e->getMessage(),
            ]);
            $payload = null;
        }

        // Process payload (with or without Persona API data)
        $kyc = $payload
            ? $this->kycHandler->processPayload($payload)
            : $this->kycHandler->processPayload([
                'data' => [
                    'type' => 'inquiry',
                    'id' => $validated['inquiryId'],
                    'attributes' => [
                        'status' => $validated['status'],
                        'reference-id' => "user_{$user->id}",
                    ],
                ],
            ]);

        $kyc ??= $user->kycVerification;

        // Reload user to get latest status
        $user->refresh();

        Log::info('KYC complete: Processing finished', [
            'user_id' => $user->id,
            'inquiry_id' => $validated['inquiryId'],
            'kyc_id' => $kyc?->id,
            'kyc_status' => $kyc?->status,
            'user_kyc_status' => $user->kyc_status,
            'user_is_verified' => $user->is_verified,
        ]);

        return response()->json([
            'kyc' => $kyc,
            'user' => [
                'is_verified' => $user->is_verified,
                'kyc_status' => $user->kyc_status,
                'has_completed_kyc' => $user->has_completed_kyc,
            ],
        ]);
    }
}

