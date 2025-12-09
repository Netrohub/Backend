<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PersonaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillPersonaPhoneNumbers extends Command
{
    protected $signature = 'kyc:backfill-phones 
                            {--dry-run : Show what would be updated without making changes}
                            {--limit=50 : Maximum number of users to process}';

    protected $description = 'Backfill phone numbers for users who completed KYC but don\'t have verified_phone';

    public function __construct(
        private PersonaService $personaService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info("Finding verified users without phone numbers...");

        // Find users who are verified but don't have verified_phone
        $users = User::where('kyc_status', 'verified')
            ->where(function ($query) {
                $query->whereNull('verified_phone')
                    ->orWhere('verified_phone', '');
            })
            ->whereNotNull('persona_inquiry_id')
            ->limit($limit)
            ->get();

        if ($users->isEmpty()) {
            $this->info('No users found that need phone number backfill.');
            return Command::SUCCESS;
        }

        $this->info("Found {$users->count()} users to process.");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        foreach ($users as $user) {
            $this->line("Processing user #{$user->id} ({$user->email})...");

            if (!$user->persona_inquiry_id) {
                $this->warn("  ⚠️  No persona_inquiry_id, skipping");
                $skippedCount++;
                continue;
            }

            try {
                $phone = null;
                
                // First, try to extract from existing KYC record's persona_data (from webhooks)
                $kyc = $user->kycVerification;
                if ($kyc && !empty($kyc->persona_data)) {
                    $this->line("  📞 Trying to extract from stored KYC data...");
                    $phone = $this->extractPhoneNumber($kyc->persona_data);
                    if ($phone) {
                        $this->line("  ✅ Found phone in stored webhook data!");
                    } else {
                        $this->line("  ℹ️  No phone found in stored data");
                    }
                } else {
                    $this->line("  ℹ️  No stored KYC data available");
                }
                
                // If not found in stored data, try fetching from Persona API (optional)
                if (!$phone) {
                    $this->line("  📞 Attempting to fetch from Persona API...");
                    try {
                        $inquiry = $this->personaService->getInquiry($user->persona_inquiry_id);
                        $phone = $this->extractPhoneNumber($inquiry);
                        if ($phone) {
                            $this->line("  ✅ Found phone via API!");
                        }
                    } catch (\Throwable $apiError) {
                        $this->warn("  ⚠️  Persona API unavailable: {$apiError->getMessage()}");
                        $this->warn("  ⚠️  Phone number not available (API authentication issue)");
                        // Don't skip - continue to next user
                        $skippedCount++;
                        continue;
                    }
                }

                if (!$phone) {
                    $this->warn("  ⚠️  No phone number found in inquiry");
                    $skippedCount++;
                    continue;
                }

                // Sanitize phone number
                $phone = $this->sanitizePhoneNumber($phone);

                if (!$phone) {
                    $this->warn("  ⚠️  Phone number failed sanitization");
                    $skippedCount++;
                    continue;
                }

                if ($dryRun) {
                    $this->info("  ✅ Would update: {$user->email} -> {$phone}");
                    $successCount++;
                } else {
                    // Update user
                    $user->verified_phone = $phone;
                    $user->phone_verified_at = now();
                    if (!$user->phone || $user->phone !== $phone) {
                        $user->phone = $phone;
                    }
                    $user->save();

                    $this->info("  ✅ Updated: {$user->email} -> {$phone}");
                    $successCount++;

                    Log::info('BackfillPersonaPhoneNumbers: Phone number backfilled', [
                        'user_id' => $user->id,
                        'inquiry_id' => $user->persona_inquiry_id,
                        'phone' => $phone,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->error("  ❌ Error: {$e->getMessage()}");
                $errorCount++;

                Log::error('BackfillPersonaPhoneNumbers: Failed to backfill phone', [
                    'user_id' => $user->id,
                    'inquiry_id' => $user->persona_inquiry_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  ✅ Success: {$successCount}");
        $this->info("  ⚠️  Skipped: {$skippedCount}");
        $this->info("  ❌ Errors: {$errorCount}");

        return Command::SUCCESS;
    }

    private function extractPhoneNumber(array $payload): ?string
    {
        // Persona API returns phone in different structures:
        // 1. Direct API response: payload['data']['attributes']['fields']['phone_number']['value']
        // 2. Webhook event: payload['data']['attributes']['payload']['data']['attributes']['fields']['phone_number']['value']
        // 3. Webhook inquiry: payload['data']['attributes']['fields']['phone_number']['value']
        // 4. Stored data might have different structure
        
        $phoneValue = null;
        $paths = [
            // Direct API response structure
            $payload['data']['attributes']['fields']['phone_number']['value'] ?? null,
            // Webhook event structure (nested)
            $payload['data']['attributes']['payload']['data']['attributes']['fields']['phone_number']['value'] ?? null,
            // Alternative webhook structure
            $payload['data']['attributes']['fields']['phone_number'] ?? null,
            // Try without 'value' wrapper (some Persona versions)
            $payload['data']['attributes']['payload']['data']['attributes']['fields']['phone_number'] ?? null,
        ];
        
        foreach ($paths as $path) {
            if (is_string($path) && !empty(trim($path))) {
                $phoneValue = $path;
                break;
            }
            // Handle if phone_number is an array with 'value' key
            if (is_array($path) && isset($path['value']) && is_string($path['value'])) {
                $phoneValue = $path['value'];
                break;
            }
        }
        
        if (!$phoneValue || !is_string($phoneValue)) {
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
            return null;
        }

        // Ensure it starts with + or add country code default
        if (!str_starts_with($cleaned, '+')) {
            // If it starts with 0, assume Saudi Arabia (966)
            if (str_starts_with($cleaned, '0')) {
                $cleaned = '+966' . substr($cleaned, 1);
            } elseif (str_starts_with($cleaned, '966')) {
                // Already has country code, just add +
                $cleaned = '+' . $cleaned;
            } else {
                // Try to detect if it's already a country code
                if (strlen($cleaned) >= 10) {
                    $cleaned = '+' . $cleaned;
                } else {
                    return null;
                }
            }
        }

        return $cleaned;
    }
}

