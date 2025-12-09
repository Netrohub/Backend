<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // PostgreSQL doesn't support ->after(), columns will be added at the end
            $table->string('persona_inquiry_id')->nullable()->unique();
            $table->string('persona_reference_id')->nullable();
            $table->enum('kyc_status', ['pending', 'verified', 'failed', 'expired', 'canceled', 'review'])->default('pending');
            $table->timestamp('kyc_verified_at')->nullable();
            $table->string('verified_phone')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
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

