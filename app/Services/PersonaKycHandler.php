<?php

namespace App\Services;

use App\Helpers\AuditHelper;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersonaKycHandler
{
    public function processPayload(array $payload, ?string $webhookEventId = null): ?KycVerification
    {
        // Handle Persona webhook event structure
        // Webhooks have: data.attributes.name (event type) and data.attributes.payload.data (inquiry data)
        $eventName = $payload['data']['attributes']['name'] ?? null;
        $inquiry = $payload['data']['attributes']['payload']['data'] ?? $payload['data'] ?? null;
        
        // If it's a webhook event, extract the inquiry from payload
        if ($eventName && isset($payload['data']['attributes']['payload']['data'])) {
            $inquiry = $payload['data']['attributes']['payload']['data'];
        }
        
        if (!$inquiry || !is_array($inquiry)) {
            Log::warning('PersonaKycHandler: Missing inquiry payload', [
                'payload_structure' => array_keys($payload),
                'has_event_name' => !is_null($eventName),
                'event_name' => $eventName,
            ]);
            return null;
        }

        $inquiryId = $inquiry['id'] ?? null;
        $attributes = $inquiry['attributes'] ?? [];
        $referenceId = $attributes['reference-id'] ?? null;
        $status = $attributes['status'] ?? null;

        if (!$inquiryId) {
            Log::warning('PersonaKycHandler: Inquiry ID missing', [
                'inquiry_keys' => $inquiry ? array_keys($inquiry) : [],
                'attributes_keys' => array_keys($attributes),
            ]);
            return null;
        }

        Log::info('PersonaKycHandler: Processing payload', [
            'inquiry_id' => $inquiryId,
            'reference_id' => $referenceId,
            'status' => $status,
            'event_name' => $eventName,
            'webhook_event_id' => $webhookEventId,
        ]);

        // Wrap everything in a transaction for data integrity
        return DB::transaction(function () use ($inquiryId, $referenceId, $status, $payload, $webhookEventId, $eventName) {
            // Use lock to prevent concurrent processing
            $kyc = KycVerification::lockForUpdate()
                ->where('persona_inquiry_id', $inquiryId)
                ->first();

            // Check for webhook replay (prevent duplicate processing)
            if ($kyc && $webhookEventId && $kyc->last_webhook_event_id === $webhookEventId) {
                Log::info('PersonaKycHandler: Webhook already processed (replay protection)', [
                    'inquiry_id' => $inquiryId,
                    'webhook_event_id' => $webhookEventId,
                ]);
                return $kyc;
            }

            if (!$kyc) {
                $user = $this->resolveUser($referenceId);
                if (!$user) {
                    Log::warning('PersonaKycHandler: Unable to resolve user for inquiry', [
                        'inquiry_id' => $inquiryId,
                        'reference_id' => $referenceId,
                    ]);
                    return null;
                }

                $kyc = KycVerification::create([
                    'user_id' => $user->id,
                    'persona_inquiry_id' => $inquiryId,
                    'status' => 'pending',
                    'persona_data' => [],
                ]);

                Log::info('PersonaKycHandler: Created new KYC record', [
                    'kyc_id' => $kyc->id,
                    'user_id' => $user->id,
                    'inquiry_id' => $inquiryId,
                ]);
            }

            $oldStatus = $kyc->status;
            $oldUserStatus = $kyc->user?->kyc_status;

            // Update KYC record
            $kyc->persona_inquiry_id = $inquiryId;
            $kyc->persona_data = array_merge($kyc->persona_data ?? [], $payload);
            $kyc->status = $this->mapStatus($status);
            if ($kyc->status === 'verified' && !$kyc->verified_at) {
                $kyc->verified_at = now();
            }
            
            // Track webhook processing
            if ($webhookEventId) {
                $kyc->last_webhook_event_id = $webhookEventId;
                $kyc->webhook_processed_at = now();
            }
            
            $kyc->save();

            // Update user record with lock
            $user = User::lockForUpdate()->find($kyc->user_id);
            if (!$user) {
                Log::error('PersonaKycHandler: User not found for KYC', [
                    'kyc_id' => $kyc->id,
                    'user_id' => $kyc->user_id,
                ]);
                return $kyc;
            }

            $userChanged = false;
            $changes = [];

            if ($referenceId && $user->persona_reference_id !== $referenceId) {
                $user->persona_reference_id = $referenceId;
                $userChanged = true;
                $changes['persona_reference_id'] = $referenceId;
            }

            if ($user->persona_inquiry_id !== $inquiryId) {
                $user->persona_inquiry_id = $inquiryId;
                $userChanged = true;
                $changes['persona_inquiry_id'] = $inquiryId;
            }

            if ($user->kyc_status !== $kyc->status) {
                $changes['kyc_status'] = ['old' => $user->kyc_status, 'new' => $kyc->status];
                $user->kyc_status = $kyc->status;
                $userChanged = true;
            }

            if ($kyc->status === 'verified' && !$user->kyc_verified_at) {
                $user->kyc_verified_at = now();
                $userChanged = true;
                $changes['kyc_verified_at'] = now()->toIso8601String();
            }

            if ($kyc->status === 'verified' && !$user->is_verified) {
                $user->is_verified = true;
                $userChanged = true;
                $changes['is_verified'] = true;
            }

            $phone = $this->extractPhoneNumber($payload);
            if ($phone) {
                $phone = $this->sanitizePhoneNumber($phone);
                if ($phone) {
                    if ($user->verified_phone !== $phone) {
                        $user->verified_phone = $phone;
                        $userChanged = true;
                        $changes['verified_phone'] = $phone;
                    }

                    if ($user->phone !== $phone) {
                        $user->phone = $phone;
                        $userChanged = true;
                        $changes['phone'] = $phone;
                    }

                    $user->phone_verified_at = now();
                    $userChanged = true;
                    $changes['phone_verified_at'] = now()->toIso8601String();
                    
                    Log::info('PersonaKycHandler: Phone number extracted and will be saved', [
                        'user_id' => $user->id,
                        'inquiry_id' => $inquiryId,
                        'phone' => $phone,
                    ]);
                } else {
                    Log::warning('PersonaKycHandler: Phone number failed sanitization', [
                        'raw_phone' => $this->extractPhoneNumber($payload),
                        'user_id' => $user->id,
                        'inquiry_id' => $inquiryId,
                    ]);
                }
            } else {
                $hasData = isset($payload['data']);
                $hasAttributes = isset($payload['data']['attributes']);
                $hasFields = isset($payload['data']['attributes']['fields']);
                $hasNestedFields = isset($payload['data']['attributes']['payload']['data']['attributes']['fields']);
                
                Log::info('PersonaKycHandler: No phone number in payload', [
                    'user_id' => $user->id,
                    'inquiry_id' => $inquiryId,
                    'payload_structure' => [
                        'has_data' => $hasData,
                        'has_attributes' => $hasAttributes,
                        'has_fields' => $hasFields,
                        'has_nested_fields' => $hasNestedFields,
                    ],
                ]);
            }

            if ($userChanged) {
                try {
                    $saved = $user->save();
                    if (!$saved) {
                        Log::error('PersonaKycHandler: User save returned false', [
                            'user_id' => $user->id,
                            'inquiry_id' => $inquiryId,
                            'changes' => $changes,
                            'user_attributes' => $user->getAttributes(),
                        ]);
                        throw new \Exception('Failed to save user KYC status - save() returned false');
                    }
                    
                    // Verify the save actually persisted by refreshing and checking
                    $user->refresh();
                    if ($user->kyc_status !== $kyc->status) {
                        Log::error('PersonaKycHandler: User save did not persist kyc_status', [
                            'user_id' => $user->id,
                            'inquiry_id' => $inquiryId,
                            'expected_status' => $kyc->status,
                            'actual_status' => $user->kyc_status,
                        ]);
                        throw new \Exception('User save did not persist kyc_status correctly');
                    }
                    
                    Log::info('PersonaKycHandler: User saved successfully', [
                        'user_id' => $user->id,
                        'inquiry_id' => $inquiryId,
                        'kyc_status' => $user->kyc_status,
                        'is_verified' => $user->is_verified,
                        'has_verified_phone' => !is_null($user->verified_phone),
                        'verified_phone' => $user->verified_phone,
                    ]);
                    
                    // Audit log for KYC status changes
                    if (isset($changes['kyc_status'])) {
                        AuditHelper::log(
                            'kyc.status_changed',
                            User::class,
                            $user->id,
                            ['kyc_status' => $oldStatus],
                            ['kyc_status' => $kyc->status, 'inquiry_id' => $inquiryId, 'webhook_event_id' => $webhookEventId],
                            null,
                            $user->id
                        );
                    }

                    Log::info('PersonaKycHandler: User updated', [
                        'user_id' => $user->id,
                        'inquiry_id' => $inquiryId,
                        'kyc_status' => $user->kyc_status,
                        'is_verified' => $user->is_verified,
                        'has_verified_phone' => !is_null($user->verified_phone),
                        'changes' => $changes,
                    ]);
                } catch (\Exception $e) {
                    Log::error('PersonaKycHandler: Exception saving user', [
                        'user_id' => $user->id,
                        'inquiry_id' => $inquiryId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'changes' => $changes,
                    ]);
                    throw $e; // Re-throw to trigger transaction rollback
                }
            } else {
                Log::debug('PersonaKycHandler: User not changed, skipping save', [
                    'user_id' => $user->id,
                    'inquiry_id' => $inquiryId,
                    'current_kyc_status' => $user->kyc_status,
                    'kyc_status' => $kyc->status,
                ]);
            }

            // Send notifications
            if ($kyc->status === 'verified') {
                $user->notify(new \App\Notifications\KycVerified($kyc, true));
                Log::info('PersonaKycHandler: KYC verified notification sent', [
                    'user_id' => $user->id,
                    'inquiry_id' => $inquiryId,
                ]);
            } elseif (in_array($kyc->status, ['failed', 'expired'], true)) {
                $user->notify(new \App\Notifications\KycVerified($kyc, false));
            }

            return $kyc;
        });
    }

    private function resolveUser(?string $referenceId): ?User
    {
        if (!$referenceId || !Str::startsWith($referenceId, 'user_')) {
            return null;
        }

        $userId = (int) str_replace('user_', '', $referenceId);
        if ($userId <= 0) {
            return null;
        }

        return User::find($userId);
    }

    private function mapStatus(?string $status): string
    {
        return match ($status) {
            'completed',
            'completed.approved' => 'verified',
            'completed.declined',
            'failed',
            'canceled' => 'failed',
            'expired' => 'expired',
            default => 'pending',
        };
    }

    private function extractPhoneNumber(array $payload): ?string
    {
        // Try multiple paths for phone number (webhook vs direct inquiry)
        $fields = $payload['data']['attributes']['fields'] ?? 
                  $payload['data']['attributes']['payload']['data']['attributes']['fields'] ?? 
                  [];
        
        $phoneValue = $fields['phone_number']['value'] ?? null;
        if (!is_string($phoneValue)) {
            Log::debug('PersonaKycHandler: Phone number not found in payload', [
                'fields_keys' => array_keys($fields),
            ]);
            return null;
        }

        $clean = trim($phoneValue);
        return $clean === '' ? null : $clean;
    }

    private function sanitizePhoneNumber(string $phone): ?string
    {
        // Remove all non-digit characters except +
        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        
        // Validate length (E.164 format: max 15 digits after +)
        if (strlen($cleaned) > 16 || strlen($cleaned) < 10) {
            Log::warning('PersonaKycHandler: Phone number length invalid', [
                'original' => $phone,
                'cleaned' => $cleaned,
                'length' => strlen($cleaned),
            ]);
            return null;
        }

        // Ensure it starts with + or add country code default
        if (!str_starts_with($cleaned, '+')) {
            // If it starts with 0, assume Saudi Arabia (966)
            if (str_starts_with($cleaned, '0')) {
                $cleaned = '+966' . substr($cleaned, 1);
            } else {
                // Try to detect if it's already a country code
                if (strlen($cleaned) >= 10) {
                    $cleaned = '+' . $cleaned;
                } else {
                    Log::warning('PersonaKycHandler: Phone number format unclear', [
                        'original' => $phone,
                        'cleaned' => $cleaned,
                    ]);
                    return null;
                }
            }
        }

        return $cleaned;
    }
}

