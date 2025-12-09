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
        Schema::table('payments', function (Blueprint $table) {
            // Add Paylink fields
            $table->string('paylink_transaction_no')->nullable()->unique()->after('tap_charge_id');
            $table->json('paylink_response')->nullable()->after('tap_response');
            
            // Make tap_charge_id nullable (since we're migrating to Paylink)
            $table->string('tap_charge_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['paylink_transaction_no', 'paylink_response']);
            // Revert tap_charge_id to not nullable if needed
            $table->string('tap_charge_id')->nullable(false)->change();
        });
    }
};
