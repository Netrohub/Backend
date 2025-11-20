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
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // Add fee columns after amount
            if (!Schema::hasColumn('withdrawal_requests', 'fee_amount')) {
                $table->decimal('fee_amount', 10, 2)->default(0)->after('amount');
            }
            if (!Schema::hasColumn('withdrawal_requests', 'fee_percentage')) {
                $table->decimal('fee_percentage', 5, 2)->default(0)->after('fee_amount');
            }
            if (!Schema::hasColumn('withdrawal_requests', 'net_amount')) {
                $table->decimal('net_amount', 10, 2)->nullable()->after('fee_percentage');
            }
        });
        
        // Update existing records: calculate net_amount = amount - fee_amount
        // For existing records without fees, net_amount = amount
        DB::statement('UPDATE withdrawal_requests SET net_amount = amount WHERE net_amount IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn(['fee_amount', 'fee_percentage', 'net_amount']);
        });
    }
};
