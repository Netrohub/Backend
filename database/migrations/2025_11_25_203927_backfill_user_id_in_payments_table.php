<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Backfill user_id for existing payments from their orders
     */
    public function up(): void
    {
        // Update payments with user_id from their associated orders
        // PostgreSQL-compatible syntax using FROM clause
        DB::statement('
            UPDATE payments 
            SET user_id = orders.buyer_id 
            FROM orders 
            WHERE payments.order_id = orders.id 
            AND payments.user_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     * Note: This cannot be fully reversed as we don't know which user_ids were backfilled
     */
    public function down(): void
    {
        // Set user_id to NULL for payments that were backfilled
        // This is a best-effort reversal - some payments may have been created with user_id
        DB::statement('
            UPDATE payments 
            SET payments.user_id = NULL 
            WHERE payments.user_id IS NOT NULL
        ');
    }
};
