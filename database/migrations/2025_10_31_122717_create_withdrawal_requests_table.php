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
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->string('bank_account'); // Bank account details (IBAN, account number, etc.)
            $table->string('status')->default('pending'); // pending, processing, completed, failed, cancelled
            $table->string('tap_transfer_id')->nullable(); // Tap Payments transfer/transaction ID
            $table->text('failure_reason')->nullable(); // Reason if withdrawal failed
            $table->json('tap_response')->nullable(); // Full response from Tap API
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
