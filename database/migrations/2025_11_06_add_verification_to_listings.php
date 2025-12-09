<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('verification_code')->nullable()->after('status');
            $table->string('verification_screenshot')->nullable()->after('verification_code');
            $table->boolean('verification_approved')->default(false)->after('verification_screenshot');
            $table->timestamp('verified_at')->nullable()->after('verification_approved');
            $table->foreignId('verified_by')->nullable()->constrained('users')->after('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn([
                'verification_code',
                'verification_screenshot',
                'verification_approved',
                'verified_at',
                'verified_by',
            ]);
        });
    }
};

