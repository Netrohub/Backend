<?php

namespace App\Console\Commands;

use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Console\Command;

class ViewPersonaData extends Command
{
    protected $signature = 'kyc:view-data 
                            {--user-id= : View data for specific user ID}
                            {--inquiry-id= : View data for specific inquiry ID}
                            {--limit=5 : Number of records to show}';

    protected $description = 'View stored Persona data from kyc_verifications table';

    public function handle(): int
    {
        $userId = $this->option('user-id');
        $inquiryId = $this->option('inquiry-id');
        $limit = (int) $this->option('limit');

        $query = KycVerification::with('user')
            ->whereNotNull('persona_inquiry_id');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($inquiryId) {
            $query->where('persona_inquiry_id', $inquiryId);
        }

        $kycs = $query->latest()->limit($limit)->get();

        if ($kycs->isEmpty()) {
            $this->warn('No KYC records found.');
            return Command::SUCCESS;
        }

        foreach ($kycs as $kyc) {
            $this->newLine();
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("KYC ID: {$kyc->id}");
            $this->info("User: {$kyc->user->email} (ID: {$kyc->user_id})");
            $this->info("Inquiry ID: {$kyc->persona_inquiry_id}");
            $this->info("Status: {$kyc->status}");
            $this->info("Verified At: " . ($kyc->verified_at?->format('Y-m-d H:i:s') ?? 'N/A'));
            $this->info("User Verified Phone: " . ($kyc->user->verified_phone ?? 'NOT SET'));

            if (empty($kyc->persona_data)) {
                $this->warn("⚠️  No persona_data stored");
                continue;
            }

            $data = $kyc->persona_data;
            $this->info("Has persona_data: YES");

            // Try to extract phone number
            $phonePaths = [
                'Direct' => $data['data']['attributes']['fields']['phone_number']['value'] ?? null,
                'Webhook Event' => $data['data']['attributes']['payload']['data']['attributes']['fields']['phone_number']['value'] ?? null,
                'Direct (no value)' => $data['data']['attributes']['fields']['phone_number'] ?? null,
            ];

            $this->info("Phone Number Extraction:");
            foreach ($phonePaths as $pathName => $phone) {
                if ($phone) {
                    if (is_array($phone) && isset($phone['value'])) {
                        $this->line("  ✅ {$pathName}: {$phone['value']}");
                    } elseif (is_string($phone)) {
                        $this->line("  ✅ {$pathName}: {$phone}");
                    }
                } else {
                    $this->line("  ❌ {$pathName}: NOT FOUND");
                }
            }

            // Show available fields
            $fields = $data['data']['attributes']['fields'] ?? [];
            if (!empty($fields)) {
                $this->info("Available Fields: " . implode(', ', array_keys($fields)));
            }

            // Show nested fields if exists
            $nestedFields = $data['data']['attributes']['payload']['data']['attributes']['fields'] ?? [];
            if (!empty($nestedFields)) {
                $this->info("Nested Fields (webhook): " . implode(', ', array_keys($nestedFields)));
            }

            // Show full structure (optional, can be verbose)
            if ($this->option('verbose')) {
                $this->newLine();
                $this->line("Full persona_data structure:");
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }

        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return Command::SUCCESS;
    }
}


