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
        // Add indexes for frequently queried columns
        // Using raw SQL to check if index exists (works across PostgreSQL, MySQL, SQLite)
        
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        
        // Users table - email index (unique constraint already creates index, but explicit helps)
        // Skip - email already has unique index from unique constraint
        
        // Listings table - category index for filtering
        try {
            if ($driver === 'pgsql') {
                $indexExists = $connection->selectOne(
                    "SELECT 1 FROM pg_indexes WHERE tablename = 'listings' AND indexname = 'listings_category_index'"
                );
                if (!$indexExists) {
                    Schema::table('listings', function (Blueprint $table) {
                        $table->index('category', 'listings_category_index');
                    });
                }
            } else {
                // For MySQL/SQLite, just try to add and catch error if exists
                Schema::table('listings', function (Blueprint $table) {
                    $table->index('category', 'listings_category_index');
                });
            }
        } catch (\Exception $e) {
            // Index might already exist, continue
        }

        // Listings table - created_at index for sorting
        try {
            if ($driver === 'pgsql') {
                $indexExists = $connection->selectOne(
                    "SELECT 1 FROM pg_indexes WHERE tablename = 'listings' AND indexname = 'listings_created_at_index'"
                );
                if (!$indexExists) {
                    Schema::table('listings', function (Blueprint $table) {
                        $table->index('created_at', 'listings_created_at_index');
                    });
                }
            } else {
                Schema::table('listings', function (Blueprint $table) {
                    $table->index('created_at', 'listings_created_at_index');
                });
            }
        } catch (\Exception $e) {
            // Index might already exist, continue
        }

        // Orders table - created_at index for sorting
        try {
            if ($driver === 'pgsql') {
                $indexExists = $connection->selectOne(
                    "SELECT 1 FROM pg_indexes WHERE tablename = 'orders' AND indexname = 'orders_created_at_index'"
                );
                if (!$indexExists) {
                    Schema::table('orders', function (Blueprint $table) {
                        $table->index('created_at', 'orders_created_at_index');
                    });
                }
            } else {
                Schema::table('orders', function (Blueprint $table) {
                    $table->index('created_at', 'orders_created_at_index');
                });
            }
        } catch (\Exception $e) {
            // Index might already exist, continue
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
