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
            // Add HyperPay fields
            $table->string('hyperpay_checkout_id')->nullable()->unique()->after('paylink_transaction_no');
            $table->json('hyperpay_response')->nullable()->after('paylink_response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['hyperpay_checkout_id', 'hyperpay_response']);
        });
    }
};
