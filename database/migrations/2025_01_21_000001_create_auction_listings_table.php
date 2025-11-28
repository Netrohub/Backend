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
        Schema::create('auction_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->onDelete('cascade')->comment('Reference to original listing');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('Seller');
            $table->enum('status', ['pending_approval', 'approved', 'live', 'ended', 'cancelled'])->default('pending_approval');
            $table->decimal('starting_bid', 10, 2)->nullable()->comment('Set by admin after approval');
            $table->decimal('current_bid', 10, 2)->nullable()->comment('Current highest bid');
            $table->foreignId('current_bidder_id')->nullable()->constrained('users')->onDelete('set null')->comment('Current highest bidder');
            $table->timestamp('starts_at')->nullable()->comment('When auction goes live');
            $table->timestamp('ends_at')->nullable()->comment('When auction ends');
            $table->integer('bid_count')->default(0)->comment('Total number of bids');
            $table->text('admin_notes')->nullable()->comment('Admin notes during approval');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null')->comment('Admin who approved');
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_maxed_account')->default(false)->comment('Admin verified maxed account');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['status', 'ends_at']);
            $table->index('user_id');
            $table->index('current_bidder_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auction_listings');
    }
};

