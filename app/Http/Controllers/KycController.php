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

        // Always return a consistent response - null if no KYC exists
        return response()->json($kyc ?? null);
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

        try {
            $personaResponse = $this->personaService->createInquiry($inquiryData);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Persona API Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'فشل الاتصال بخدمة التحقق. يرجى المحاولة لاحقاً.',
                'error' => $e->getMessage()
            ], 500);
        }

        // Check for Persona API errors
        if (isset($personaResponse['errors']) && !empty($personaResponse['errors'])) {
            $errorMessage = $personaResponse['errors'][0]['title'] ?? 'فشل إنشاء طلب التحقق';
            \Illuminate\Support\Facades\Log::error('Persona API Error Response', [
                'errors' => $personaResponse['errors'],
            ]);
            return response()->json([
                'message' => $errorMessage,
                'errors' => $personaResponse['errors']
            ], 500);
        }

        if (!isset($personaResponse['data']['id'])) {
            \Illuminate\Support\Facades\Log::error('Persona API: Missing inquiry ID', [
                'response' => $personaResponse,
            ]);
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
