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

        if ($user->is_verified) {
            return response()->json([
                'message' => 'KYC already verified',
                'kyc' => $user->kycVerification,
            ]);
        }

        $validated = $request->validate([
            'inquiryId' => 'required|string',
            'status' => 'required|string',
            'userId' => 'required|integer',
        ]);

        if ((int) $validated['userId'] !== $user->id) {
            return response()->json([
                'message' => 'User mismatch',
            ], 403);
        }

        try {
            $payload = $this->personaService->getInquiry($validated['inquiryId']);
        } catch (\Throwable $e) {
            Log::warning('KYC complete: Unable to fetch inquiry details', [
                'user_id' => $user->id,
                'inquiry_id' => $validated['inquiryId'],
                'error' => $e->getMessage(),
            ]);
            $payload = null;
        }

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

        return response()->json([
            'kyc' => $kyc,
        ]);
    }
}

