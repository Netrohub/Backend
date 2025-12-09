<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add 'paused' and 'rejected' statuses to auction_listings enum.
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
                WHERE tc.table_name = 'auction_listings'
                AND tc.constraint_type = 'CHECK'
                AND cc.check_clause LIKE '%status%'
                LIMIT 1
            ");
            
            // Drop existing constraint if found
            if ($constraint && isset($constraint->constraint_name)) {
                $constraintName = $constraint->constraint_name;
                DB::statement("ALTER TABLE auction_listings DROP CONSTRAINT IF EXISTS \"{$constraintName}\"");
            }
            
            // Add new CHECK constraint with paused and rejected
            DB::statement("
                ALTER TABLE auction_listings 
                ADD CONSTRAINT auction_listings_status_check 
                CHECK (status IN ('pending_approval', 'approved', 'live', 'ended', 'cancelled', 'paused', 'rejected'))
            ");
        } elseif ($driver === 'sqlite') {
            // SQLite: Limited ALTER TABLE support
            // SQLite doesn't support MODIFY COLUMN, ENUM, or adding CHECK constraints via ALTER TABLE
            // We can only update the data - the application layer will enforce enum values
            // Note: For SQLite, enum validation happens at the application level (Laravel validation)
        } else {
            // MySQL/MariaDB: Use MODIFY COLUMN
            DB::statement("ALTER TABLE auction_listings MODIFY COLUMN status ENUM('pending_approval', 'approved', 'live', 'ended', 'cancelled', 'paused', 'rejected') DEFAULT 'pending_approval'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            // Find and drop the constraint
            $constraint = DB::selectOne("
                SELECT tc.constraint_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.check_constraints cc ON tc.constraint_name = cc.constraint_name
                WHERE tc.table_name = 'auction_listings'
                AND tc.constraint_type = 'CHECK'
                AND cc.check_clause LIKE '%status%'
                LIMIT 1
            ");
            
            if ($constraint && isset($constraint->constraint_name)) {
                $constraintName = $constraint->constraint_name;
                DB::statement("ALTER TABLE auction_listings DROP CONSTRAINT IF EXISTS \"{$constraintName}\"");
            }
            
            // Restore old constraint
            DB::statement("
                ALTER TABLE auction_listings 
                ADD CONSTRAINT auction_listings_status_check 
                CHECK (status IN ('pending_approval', 'approved', 'live', 'ended', 'cancelled'))
            ");
            
            // Update any paused or rejected auctions back to a valid status
            DB::table('auction_listings')->where('status', 'paused')->update(['status' => 'live']);
            DB::table('auction_listings')->where('status', 'rejected')->update(['status' => 'cancelled']);
        } elseif ($driver === 'sqlite') {
            // No action needed for SQLite
        } else {
            // MySQL/MariaDB: Restore old enum
            DB::statement("ALTER TABLE auction_listings MODIFY COLUMN status ENUM('pending_approval', 'approved', 'live', 'ended', 'cancelled') DEFAULT 'pending_approval'");
        }
    }
};

