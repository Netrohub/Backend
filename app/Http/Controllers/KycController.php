<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use App\Services\PersonaService;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(
        private PersonaService $personaService
    ) {}

    public function index(Request $request)
    {
        $kyc = KycVerification::where('user_id', $request->user()->id)->first();

        return response()->json($kyc);
    }

    public function create(Request $request)
    {
        $user = $request->user();

        // Check if KYC already exists
        $existingKyc = KycVerification::where('user_id', $user->id)->first();
        if ($existingKyc && $existingKyc->status === 'verified') {
            return response()->json(['message' => 'KYC already verified'], 400);
        }

        // Create Persona inquiry
        $inquiryData = [
            'reference-id' => 'user_' . $user->id,
            'fields' => [
                'name-first' => explode(' ', $user->name)[0] ?? '',
                'name-last' => explode(' ', $user->name)[1] ?? '',
                'email-address' => $user->email,
            ],
        ];

        $personaResponse = $this->personaService->createInquiry($inquiryData);

        if (!isset($personaResponse['data']['id'])) {
            return response()->json(['message' => 'Failed to create KYC inquiry'], 500);
        }

        // Create or update KYC record
        $kyc = KycVerification::updateOrCreate(
            ['user_id' => $user->id],
            [
                'persona_inquiry_id' => $personaResponse['data']['id'],
                'status' => 'pending',
                'persona_data' => $personaResponse,
            ]
        );

        return response()->json([
            'kyc' => $kyc,
            'inquiry_url' => $personaResponse['data']['attributes']['inquiry-url'] ?? null,
        ], 201);
    }
}
