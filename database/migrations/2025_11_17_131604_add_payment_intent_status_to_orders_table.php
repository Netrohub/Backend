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
        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            // PostgreSQL: Drop old constraint and add new one
            // Find the status CHECK constraint name
            $constraint = DB::selectOne("
                SELECT tc.constraint_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.check_constraints cc ON tc.constraint_name = cc.constraint_name
                WHERE tc.table_name = 'orders'
                AND tc.constraint_type = 'CHECK'
                AND cc.check_clause LIKE '%status%'
                LIMIT 1
            ");
            
            // Drop existing constraint if found
            if ($constraint && isset($constraint->constraint_name)) {
                $constraintName = $constraint->constraint_name;
                DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS \"{$constraintName}\"");
            }
            
            // Add new CHECK constraint with payment_intent
            DB::statement("
                ALTER TABLE orders 
                ADD CONSTRAINT orders_status_check 
                CHECK (status IN ('payment_intent', 'pending', 'paid', 'escrow_hold', 'completed', 'cancelled', 'disputed'))
            ");
            
            // Set default
            DB::statement("ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'payment_intent'");
        } else {
            // MySQL/MariaDB: Use MODIFY COLUMN
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('payment_intent', 'pending', 'paid', 'escrow_hold', 'completed', 'cancelled', 'disputed') DEFAULT 'payment_intent'");
        }
        
        // Update existing 'pending' orders to 'payment_intent' to maintain consistency
        DB::table('orders')->where('status', 'pending')->update(['status' => 'payment_intent']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        
        // Convert payment_intent back to pending before removing from enum
        DB::table('orders')->where('status', 'payment_intent')->update(['status' => 'pending']);
        
        if ($driver === 'pgsql') {
            // PostgreSQL: Drop constraint and recreate without payment_intent
            DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check");
            
            DB::statement("
                ALTER TABLE orders 
                ADD CONSTRAINT orders_status_check 
                CHECK (status IN ('pending', 'paid', 'escrow_hold', 'completed', 'cancelled', 'disputed'))
            ");
            
            DB::statement("ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'pending'");
        } else {
            // MySQL/MariaDB: Remove payment_intent from enum
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'paid', 'escrow_hold', 'completed', 'cancelled', 'disputed') DEFAULT 'pending'");
        }
    }
};
