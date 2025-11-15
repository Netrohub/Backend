<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Updates existing avatar URLs from /avatar variant to /public variant
     * to fix broken avatar images in the database.
     */
    public function up(): void
    {
        // Update all users with avatars containing /avatar variant
        $updated = DB::table('users')
            ->whereNotNull('avatar')
            ->where('avatar', 'like', '%/avatar')
            ->update([
                'avatar' => DB::raw("REPLACE(avatar, '/avatar', '/public')"),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            Log::info("Updated {$updated} avatar URLs from /avatar to /public variant");
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Reverts avatar URLs back to /avatar variant (if needed for rollback)
     */
    public function down(): void
    {
        // Revert /public back to /avatar (only if you need to rollback)
        $reverted = DB::table('users')
            ->whereNotNull('avatar')
            ->where('avatar', 'like', '%/public')
            ->where('avatar', 'like', '%imagedelivery.net%')
            ->update([
                'avatar' => DB::raw("REPLACE(avatar, '/public', '/avatar')"),
                'updated_at' => now(),
            ]);

        if ($reverted > 0) {
            Log::info("Reverted {$reverted} avatar URLs from /public back to /avatar variant");
        }
    }
};
