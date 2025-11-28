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
        Schema::table('auction_listings', function (Blueprint $table) {
            // Make listing_id nullable (auction listings can be independent)
            $table->foreignId('listing_id')->nullable()->change();
            
            // Add listing fields directly to auction_listings
            $table->string('title')->nullable()->after('user_id');
            $table->text('description')->nullable()->after('title');
            $table->decimal('price', 10, 2)->nullable()->after('description')->comment('Estimated value');
            $table->string('category')->nullable()->after('price');
            $table->json('images')->nullable()->after('category');
            
            // Add encrypted credential fields
            $table->text('account_email_encrypted')->nullable()->after('images');
            $table->text('account_password_encrypted')->nullable()->after('account_email_encrypted');
            $table->json('account_metadata')->nullable()->after('account_password_encrypted')->comment('Additional account data: server, stove level, helios, etc.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auction_listings', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'description',
                'price',
                'category',
                'images',
                'account_email_encrypted',
                'account_password_encrypted',
                'account_metadata',
            ]);
            
            // Revert listing_id to not nullable
            $table->foreignId('listing_id')->nullable(false)->change();
        });
    }
};

