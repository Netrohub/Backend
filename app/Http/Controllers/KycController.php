<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class KycController extends Controller
{
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

        if ((int)$validated['userId'] !== $user->id) {
            return response()->json([
                'message' => 'User mismatch',
            ], 403);
        }

        $kyc = KycVerification::updateOrCreate(
            ['user_id' => $user->id],
            [
                'persona_inquiry_id' => $validated['inquiryId'],
                'status' => 'pending',
            ]
        );

        $existingData = $kyc->persona_data ?? [];
        $existingData['client_payload'] = [
            'status' => $validated['status'],
            'submitted_at' => now()->toIso8601String(),
            'user_id' => $user->id,
        ];
        $existingData['client_reference_id'] = "user_{$user->id}";
        $existingData['last_client_update_at'] = now()->toIso8601String();

        $kyc->persona_data = $existingData;
        $kyc->save();

        return response()->json([
            'kyc' => $kyc,
        ]);
    }
}

