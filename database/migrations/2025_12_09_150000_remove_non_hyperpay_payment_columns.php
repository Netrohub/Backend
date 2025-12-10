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
        // Remove non-HyperPay columns from payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'tap_charge_id',
                'tap_reference',
                'paylink_transaction_no',
                'paypal_order_id',
                'tap_response',
                'paylink_response',
                'paypal_response',
            ]);
        });

        // Remove non-HyperPay columns from orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'tap_charge_id',
                'paylink_transaction_no',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add columns if needed (for rollback)
        Schema::table('payments', function (Blueprint $table) {
            $table->string('tap_charge_id')->nullable();
            $table->string('tap_reference')->nullable();
            $table->string('paylink_transaction_no')->nullable();
            $table->string('paypal_order_id')->nullable();
            $table->json('tap_response')->nullable();
            $table->json('paylink_response')->nullable();
            $table->json('paypal_response')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('tap_charge_id')->nullable();
            $table->string('paylink_transaction_no')->nullable();
        });
    }
};

