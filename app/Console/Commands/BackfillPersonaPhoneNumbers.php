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
                // Fetch inquiry from Persona API
                $this->line("  📞 Fetching inquiry {$user->persona_inquiry_id} from Persona...");
                $inquiry = $this->personaService->getInquiry($user->persona_inquiry_id);

                // Extract phone number
                $phone = $this->extractPhoneNumber($inquiry);

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
        // Try multiple paths for phone number
        $paths = [
            $payload['data']['attributes']['fields']['phone_number']['value'] ?? null,
            $payload['data']['attributes']['payload']['data']['attributes']['fields']['phone_number']['value'] ?? null,
            $payload['data']['attributes']['fields']['phone_number'] ?? null,
            $payload['data']['attributes']['payload']['data']['attributes']['fields']['phone_number'] ?? null,
        ];
        
        foreach ($paths as $path) {
            if (is_string($path) && !empty(trim($path))) {
                return trim($path);
            }
            if (is_array($path) && isset($path['value']) && is_string($path['value'])) {
                return trim($path['value']);
            }
        }
        
        return null;
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
            if (str_starts_with($cleaned, '0')) {
                $cleaned = '+966' . substr($cleaned, 1);
            } else {
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

