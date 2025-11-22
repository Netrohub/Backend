<?php

namespace App\Services;

use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersonaKycHandler
{
    public function processPayload(array $payload): ?KycVerification
    {
        $inquiry = $payload['data'] ?? null;
        if (!$inquiry || !is_array($inquiry)) {
            Log::warning('PersonaKycHandler: Missing inquiry payload', ['payload' => $payload]);
            return null;
        }

        $inquiryId = $inquiry['id'] ?? null;
        $attributes = $inquiry['attributes'] ?? [];
        $referenceId = $attributes['reference-id'] ?? null;
        $status = $attributes['status'] ?? null;

        if (!$inquiryId) {
            Log::warning('PersonaKycHandler: Inquiry ID missing', ['payload' => $payload]);
            return null;
        }

        $kyc = KycVerification::where('persona_inquiry_id', $inquiryId)->first();

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
        }

        $kyc->persona_inquiry_id = $inquiryId;
        $kyc->persona_data = array_merge($kyc->persona_data ?? [], $payload);
        $kyc->status = $this->mapStatus($status);
        if ($kyc->status === 'verified') {
            $kyc->verified_at = now();
        }
        $kyc->save();

        $user = $kyc->user;
        if ($user) {
            $userChanged = false;
            if ($referenceId && $user->persona_reference_id !== $referenceId) {
                $user->persona_reference_id = $referenceId;
                $userChanged = true;
            }

            if ($user->persona_inquiry_id !== $inquiryId) {
                $user->persona_inquiry_id = $inquiryId;
                $userChanged = true;
            }

            if ($user->kyc_status !== $kyc->status) {
                $user->kyc_status = $kyc->status;
                $userChanged = true;
            }

            if ($kyc->status === 'verified' && !$user->kyc_verified_at) {
                $user->kyc_verified_at = now();
                $userChanged = true;
            }

            if ($kyc->status === 'verified' && !$user->is_verified) {
                $user->is_verified = true;
                $userChanged = true;
            }

            $phone = $this->extractPhoneNumber($payload);
            if ($phone) {
                if ($user->verified_phone !== $phone) {
                    $user->verified_phone = $phone;
                    $userChanged = true;
                }

                if ($user->phone !== $phone) {
                    $user->phone = $phone;
                    $userChanged = true;
                }

                $user->phone_verified_at = now();
                $userChanged = true;
            }

            if ($userChanged) {
                $user->save();
            }
        } elseif (!$user && $referenceId) {
            Log::warning('PersonaKycHandler: KYC record missing associated user', ['kyc_id' => $kyc->id, 'reference_id' => $referenceId]);
        }

        $userForNotification = $user;
        if ($kyc->status === 'verified') {
            $userForNotification?->notify(new \App\Notifications\KycVerified($kyc, true));
        } elseif (in_array($kyc->status, ['failed', 'expired'], true)) {
            $userForNotification?->notify(new \App\Notifications\KycVerified($kyc, false));
        }

        return $kyc;
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
        $fields = $payload['data']['attributes']['fields'] ?? [];
        $phoneValue = $fields['phone_number']['value'] ?? null;
        if (!is_string($phoneValue)) {
            return null;
        }

        $clean = trim($phoneValue);
        return $clean === '' ? null : $clean;
    }
}

