<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'payment_intent' status to orders enum.
     * payment_intent = temporary order created before payment (not a real order yet)
     * Only becomes a real order (escrow_hold) after payment confirmation
     */
    public function up(): void
    {
        // Modify enum to add 'payment_intent' status
        // Note: MySQL/MariaDB requires raw SQL to modify enum
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('payment_intent', 'pending', 'paid', 'escrow_hold', 'completed', 'cancelled', 'disputed') DEFAULT 'payment_intent'");
        
        // Update existing 'pending' orders to 'payment_intent' to maintain consistency
        DB::table('orders')->where('status', 'pending')->update(['status' => 'payment_intent']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert payment_intent back to pending before removing from enum
        DB::table('orders')->where('status', 'payment_intent')->update(['status' => 'pending']);
        
        // Remove payment_intent from enum
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'paid', 'escrow_hold', 'completed', 'cancelled', 'disputed') DEFAULT 'pending'");
    }
};
