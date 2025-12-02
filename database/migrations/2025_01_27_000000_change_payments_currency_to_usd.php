<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Changes payments table currency default from SAR to USD
     * Updates existing payment records to USD
     * 
     * Note: Payment amounts are already stored in USD,
     * only the currency label needs updating to match.
     */
    public function up(): void
    {
        // Update existing payment records to USD
        // Note: Payment amounts are already stored in USD, only currency label needs updating
        DB::table('payments')
            ->where('currency', 'SAR')
            ->update(['currency' => 'USD']);

        // Change default currency to USD for new records
        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            // PostgreSQL: Use ALTER COLUMN
            DB::statement("ALTER TABLE payments ALTER COLUMN currency SET DEFAULT 'USD'");
        } elseif ($driver === 'sqlite') {
            // SQLite: Limited ALTER TABLE support
            // SQLite doesn't support changing DEFAULT value directly
            // The default will be set for new tables, existing records already updated above
            // For SQLite, the application layer should handle defaults
        } else {
            // MySQL/MariaDB: Use MODIFY COLUMN
            DB::statement("ALTER TABLE payments MODIFY COLUMN currency VARCHAR(3) DEFAULT 'USD'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert existing payment records to SAR
        DB::table('payments')
            ->where('currency', 'USD')
            ->update(['currency' => 'SAR']);

        // Change default currency back to SAR
        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE payments ALTER COLUMN currency SET DEFAULT 'SAR'");
        } elseif ($driver === 'sqlite') {
            // SQLite: Limited ALTER TABLE support
        } else {
            // MySQL/MariaDB
            DB::statement("ALTER TABLE payments MODIFY COLUMN currency VARCHAR(3) DEFAULT 'SAR'");
        }
    }
};

