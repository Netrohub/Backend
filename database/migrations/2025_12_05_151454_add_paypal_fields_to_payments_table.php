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
            // Add PayPal fields
            $table->string('paypal_order_id')->nullable()->unique()->after('hyperpay_checkout_id');
            $table->json('paypal_response')->nullable()->after('hyperpay_response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['paypal_order_id', 'paypal_response']);
        });
    }
};
