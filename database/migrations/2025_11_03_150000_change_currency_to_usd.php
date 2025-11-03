<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing payments records from SAR to USD
        DB::table('payments')->update(['currency' => 'USD']);
        
        // Change default value for payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to SAR
        DB::table('payments')->update(['currency' => 'SAR']);
        
        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('SAR')->change();
        });
    }
};

