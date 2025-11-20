<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, copy existing bank_account to iban if column doesn't exist yet
        if (!Schema::hasColumn('withdrawal_requests', 'iban')) {
            // Add iban column temporarily as nullable
            Schema::table('withdrawal_requests', function (Blueprint $table) {
                $table->string('iban')->nullable()->after('bank_account');
            });
            
            // Copy existing data
            DB::statement('UPDATE withdrawal_requests SET iban = bank_account WHERE bank_account IS NOT NULL');
        }

        // Add new required fields
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('withdrawal_requests', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('iban');
            }
            if (!Schema::hasColumn('withdrawal_requests', 'account_holder_name')) {
                $table->string('account_holder_name')->nullable()->after('bank_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn(['iban', 'bank_name', 'account_holder_name']);
        });
    }
};

