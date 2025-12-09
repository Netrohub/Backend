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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('tap_charge_id')->unique();
            $table->string('tap_reference')->nullable();
            $table->enum('status', ['initiated', 'authorized', 'captured', 'failed', 'cancelled'])->default('initiated');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('SAR');
            $table->json('tap_response')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            
            $table->index(['order_id', 'status']);
            $table->index('tap_charge_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
