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
        // Explicitly return null to ensure JSON null (not empty object)
        if (!$kyc) {
            return response()->json(null);
        }

        // Only return KYC if it has a valid status
        // If status is null, treat it as if no record exists
        if ($kyc->status === null) {
            return response()->json(null);
        }

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

        try {
            $personaResponse = $this->personaService->createInquiry($inquiryData);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Persona API Error (Sandbox)', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'template_id' => config('services.persona.template_id'),
                'environment_id' => config('services.persona.environment_id'),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Provide more specific error message for 404 errors
            $errorMessage = 'فشل الاتصال بخدمة التحقق. يرجى المحاولة لاحقاً.';
            if (str_contains($e->getMessage(), 'Record not found') || str_contains($e->getMessage(), '404')) {
                $errorMessage = 'خطأ في إعدادات التحقق. يرجى التحقق من معرف القالب وبيئة الساندبوكس في إعدادات Persona.';
            }
            
            return response()->json([
                'message' => $errorMessage,
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
