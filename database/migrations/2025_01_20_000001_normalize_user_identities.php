<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration normalizes user identities:
     * 1. Ensures all users have valid usernames (lowercase, alphanumeric + underscore, 3-20 chars)
     * 2. Migrates name to display_name where appropriate
     * 3. Fixes duplicate usernames
     * 4. Cleans up orphaned references
     */
    public function up(): void
    {
        // Step 1: Normalize existing usernames
        DB::table('users')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $username = $this->normalizeUsername($user->username ?? $user->name ?? 'user');
                
                // Check for duplicates and append suffix if needed
                $finalUsername = $username;
                $counter = 1;
                while (DB::table('users')
                    ->where('username', $finalUsername)
                    ->where('id', '!=', $user->id)
                    ->exists()) {
                    $finalUsername = $username . '_' . $counter;
                    $counter++;
                    
                    // Prevent infinite loop
                    if ($counter > 9999) {
                        $finalUsername = $username . '_' . Str::random(6);
                        break;
                    }
                }
                
                // Update user
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'username' => $finalUsername,
                        'display_name' => $user->name, // Preserve original name as display_name
                    ]);
            }
        });
        
        // Step 2: Ensure username is not null
        // Note: Unique constraint already exists from previous migration
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
        });
        
        // Step 3: Clean up orphaned foreign keys in related tables
        // This is handled by database constraints, but we can verify integrity
        $this->cleanupOrphanedReferences();
    }

    /**
     * Normalize username to match requirements: [a-z0-9_]{3,20}
     */
    private function normalizeUsername(string $input): string
    {
        // Convert to lowercase
        $username = strtolower($input);
        
        // Remove all non-alphanumeric characters except underscore
        $username = preg_replace('/[^a-z0-9_]/', '', $username);
        
        // Remove leading/trailing underscores
        $username = trim($username, '_');
        
        // Ensure minimum length (pad with numbers if needed)
        if (strlen($username) < 3) {
            $username = $username . str_pad('', 3 - strlen($username), '0');
        }
        
        // Truncate to max length
        if (strlen($username) > 20) {
            $username = substr($username, 0, 20);
        }
        
        // Final validation
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
            // Fallback: generate from ID
            $username = 'user_' . Str::random(8);
        }
        
        return $username;
    }

    /**
     * Clean up orphaned references in related tables
     */
    private function cleanupOrphanedReferences(): void
    {
        // Note: This is a safety check. Actual cleanup should be done carefully
        // as it may affect data integrity. We'll log issues rather than auto-fix.
        
        $tables = [
            'listings' => 'user_id',
            'orders' => ['buyer_id', 'seller_id'],
            'disputes' => ['initiated_by', 'resolved_by'],
            'wallets' => 'user_id',
            'user_notifications' => 'user_id',
            'audit_logs' => 'user_id',
        ];
        
        foreach ($tables as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            
            $columns = is_array($columns) ? $columns : [$columns];
            
            foreach ($columns as $column) {
                // Find orphaned references (users that don't exist)
                $orphaned = DB::table($table)
                    ->whereNotIn($column, function ($query) {
                        $query->select('id')->from('users');
                    })
                    ->count();
                
                if ($orphaned > 0) {
                    \Illuminate\Support\Facades\Log::warning("Found {$orphaned} orphaned references in {$table}.{$column}");
                    // Don't auto-delete - requires manual review
                }
            }
        }
        
        // Check for duplicate usernames (shouldn't happen after normalization, but verify)
        $duplicates = DB::table('users')
            ->select('username', DB::raw('count(*) as count'))
            ->groupBy('username')
            ->having('count', '>', 1)
            ->get();
        
        if ($duplicates->count() > 0) {
            \Illuminate\Support\Facades\Log::warning("Found duplicate usernames after normalization", [
                'duplicates' => $duplicates->pluck('username')->toArray(),
            ]);
        }
        
        // Check for users with null or empty usernames
        $nullUsernames = DB::table('users')
            ->whereNull('username')
            ->orWhere('username', '')
            ->count();
        
        if ($nullUsernames > 0) {
            \Illuminate\Support\Facades\Log::warning("Found {$nullUsernames} users with null or empty usernames after normalization");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reversing this migration is complex and may cause data loss
        // We'll just make username nullable again
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->change();
        });
    }
};

