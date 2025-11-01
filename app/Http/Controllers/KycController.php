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

    /**
     * Sync KYC status from Persona API
     * Useful when webhooks don't arrive or need manual refresh
     * Can sync by inquiryId (from embedded flow) or existing KYC record
     */
    public function sync(Request $request)
    {
        $user = $request->user();
        
        // Check if inquiryId is provided (from embedded flow completion)
        $inquiryId = $request->input('inquiry_id');
        
        // Get existing KYC record by user_id or inquiry_id
        $kyc = null;
        if ($inquiryId) {
            // Try to find by inquiry_id first (for embedded flow)
            $kyc = KycVerification::where('persona_inquiry_id', $inquiryId)->first();
            
            // If not found but inquiryId provided, create it
            if (!$kyc) {
                // Retrieve inquiry from Persona to get reference-id
                try {
                    $inquiryData = $this->personaService->retrieveInquiry($inquiryId);
                    $referenceId = $inquiryData['data']['attributes']['reference-id'] ?? null;
                    
                    // Verify reference-id matches this user
                    if ($referenceId && str_starts_with($referenceId, 'user_')) {
                        $userId = (int) str_replace('user_', '', $referenceId);
                        if ($userId === $user->id) {
                            // Create KYC record
                            $kyc = KycVerification::create([
                                'user_id' => $user->id,
                                'persona_inquiry_id' => $inquiryId,
                                'status' => 'pending',
                                'persona_data' => $inquiryData,
                            ]);
                            \Illuminate\Support\Facades\Log::info('KYC Sync: Created KYC record from inquiry', [
                                'inquiry_id' => $inquiryId,
                                'user_id' => $user->id,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('KYC Sync: Failed to retrieve inquiry', [
                        'inquiry_id' => $inquiryId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        // Fallback: Get existing KYC record by user_id
        if (!$kyc) {
            $kyc = KycVerification::where('user_id', $user->id)->first();
        }
        
        if (!$kyc || !$kyc->persona_inquiry_id) {
            return response()->json([
                'message' => 'No KYC inquiry found. Please start verification first.',
            ], 404);
        }

        try {
            // Retrieve inquiry status from Persona
            $inquiryData = $this->personaService->retrieveInquiry($kyc->persona_inquiry_id);
            
            $status = $inquiryData['data']['attributes']['status'] ?? null;
            
            if (!$status) {
                return response()->json([
                    'message' => 'Could not retrieve inquiry status from Persona',
                ], 500);
            }

            // Update KYC status based on Persona status
            $oldStatus = $kyc->status;
            $kyc->status = match($status) {
                'completed.approved' => 'verified',
                'completed.declined' => 'failed',
                'expired' => 'expired',
                default => 'pending',
            };

            if ($kyc->status === 'verified' && $oldStatus !== 'verified') {
                $kyc->verified_at = now();
                
                // Update user verification status
                $user->is_verified = true;
                $user->save();
                
                // Send verification notification
                $user->notify(new \App\Notifications\KycVerified($kyc, true));
            } elseif (($kyc->status === 'failed' || $kyc->status === 'expired') && $oldStatus !== $kyc->status) {
                // Send notification for failed/expired KYC
                $user->notify(new \App\Notifications\KycVerified($kyc, false));
            }

            $kyc->persona_data = array_merge($kyc->persona_data ?? [], $inquiryData);
            $kyc->save();

            return response()->json([
                'kyc' => $kyc,
                'synced' => true,
                'status' => $kyc->status,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('KYC Sync Error', [
                'user_id' => $user->id,
                'inquiry_id' => $kyc->persona_inquiry_id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to sync KYC status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Diagnostic endpoint to verify Persona configuration
     * This helps troubleshoot "Record not found" errors
     */
    public function verifyConfig(Request $request)
    {
        try {
            $templateInfo = $this->personaService->verifyTemplate();
            
            return response()->json([
                'status' => 'success',
                'template_id' => config('services.persona.template_id'),
                'environment_id' => config('services.persona.environment_id'),
                'template_exists' => isset($templateInfo['data']),
                'template_info' => $templateInfo,
            ]);
        } catch (\Exception $e) {
            // Try to list all templates to see what's available
            $availableTemplates = null;
            try {
                $templatesList = $this->personaService->listTemplates();
                $availableTemplates = $templatesList['data'] ?? null;
            } catch (\Exception $listException) {
                // Ignore list error, just show template verification error
            }

            return response()->json([
                'status' => 'error',
                'template_id' => config('services.persona.template_id'),
                'environment_id' => config('services.persona.environment_id'),
                'error' => $e->getMessage(),
                'message' => 'Template verification failed. Please check that the template ID exists in your Persona sandbox account.',
                'available_templates' => $availableTemplates ? array_map(function($t) {
                    return [
                        'id' => $t['id'] ?? null,
                        'name' => $t['attributes']['name'] ?? null,
                    ];
                }, $availableTemplates) : null,
            ], 404);
        }
    }
}
