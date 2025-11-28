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
        Schema::table('orders', function (Blueprint $table) {
            // Make listing_id nullable to support auction-only orders
            $table->foreignId('listing_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Note: This will fail if there are existing null values
            // In production, you'd need to handle this more carefully
            $table->foreignId('listing_id')->nullable(false)->change();
        });
    }
};

