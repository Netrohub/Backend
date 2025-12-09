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
            // Remove unused statuses: 'pending' and 'paid' (not used in codebase)
            // Real orders: escrow_hold, disputed, completed
            // Not real orders: payment_intent (temporary, no payment yet)
            DB::statement("
                ALTER TABLE orders 
                ADD CONSTRAINT orders_status_check 
                CHECK (status IN ('payment_intent', 'escrow_hold', 'completed', 'cancelled', 'disputed'))
            ");
            
            // Set default
            DB::statement("ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'payment_intent'");
        } elseif ($driver === 'sqlite') {
            // SQLite: Limited ALTER TABLE support
            // SQLite doesn't support MODIFY COLUMN, ENUM, or adding CHECK constraints via ALTER TABLE
            // We can only update the data - the application layer will enforce enum values
            // Note: For SQLite, enum validation happens at the application level (Laravel validation)
            // This is acceptable as SQLite is typically only used for local development
        } else {
            // MySQL/MariaDB: Use MODIFY COLUMN
            // Remove unused statuses: 'pending' and 'paid' (not used in codebase)
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('payment_intent', 'escrow_hold', 'completed', 'cancelled', 'disputed') DEFAULT 'payment_intent'");
        }
        
        // Update existing orders to maintain consistency
        // 'pending' -> 'payment_intent' (no payment yet, not a real order)
        // 'paid' -> 'escrow_hold' (payment was confirmed, should be a real order)
        DB::table('orders')->where('status', 'pending')->update(['status' => 'payment_intent']);
        DB::table('orders')->where('status', 'paid')->update(['status' => 'escrow_hold']);
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
                CHECK (status IN ('pending', 'escrow_hold', 'completed', 'cancelled', 'disputed'))
            ");
            
            DB::statement("ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'pending'");
        } elseif ($driver === 'sqlite') {
            // SQLite: Just update data, constraint handling is limited
            // No need to modify constraint as SQLite doesn't enforce it strictly
        } else {
            // MySQL/MariaDB: Remove payment_intent from enum
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'escrow_hold', 'completed', 'cancelled', 'disputed') DEFAULT 'pending'");
        }
    }
};
