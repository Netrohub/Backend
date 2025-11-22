<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('persona_inquiry_id')->nullable()->after('phone')->unique();
            $table->string('persona_reference_id')->nullable()->after('persona_inquiry_id');
            $table->enum('kyc_status', ['pending', 'verified', 'failed', 'expired', 'canceled', 'review'])->default('pending')->after('persona_reference_id');
            $table->timestamp('kyc_verified_at')->nullable()->after('kyc_status');
            $table->string('verified_phone')->nullable()->after('kyc_verified_at');
            $table->timestamp('phone_verified_at')->nullable()->after('verified_phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'persona_inquiry_id',
                'persona_reference_id',
                'kyc_status',
                'kyc_verified_at',
                'verified_phone',
                'phone_verified_at',
            ]);
        });
    }
};

