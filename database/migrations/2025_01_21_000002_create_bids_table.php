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
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_listing_id')->constrained('auction_listings')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('Bidder');
            $table->decimal('amount', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->nullable()->comment('Refundable deposit held in escrow');
            $table->enum('deposit_status', ['pending', 'held', 'refunded', 'applied'])->default('pending')->comment('Deposit escrow status');
            $table->boolean('is_winning_bid')->default(false)->comment('Is this the current winning bid');
            $table->boolean('is_outbid')->default(false)->comment('Was this bid outbid');
            $table->timestamp('outbid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['auction_listing_id', 'is_winning_bid']);
            $table->index(['user_id', 'deposit_status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};

