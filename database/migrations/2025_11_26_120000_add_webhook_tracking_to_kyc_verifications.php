<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->timestamp('webhook_processed_at')->nullable()->after('verified_at');
            $table->string('last_webhook_event_id')->nullable()->after('webhook_processed_at');
            $table->index('webhook_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->dropColumn(['webhook_processed_at', 'last_webhook_event_id']);
        });
    }
};

