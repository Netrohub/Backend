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
            if (!Schema::hasColumn('withdrawal_requests', 'order_breakdown')) {
                $table->json('order_breakdown')->nullable()->after('account_holder_name')->comment('JSON array of orders that contributed to this withdrawal: [{"order_id": 1, "order_number": "NXO-1", "amount": 30.00}, ...]');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('order_breakdown');
        });
    }
};

