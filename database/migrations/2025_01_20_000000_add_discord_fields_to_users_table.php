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
        Schema::table('users', function (Blueprint $table) {
            // Add display_name (nullable nickname, never used as identifier)
            $table->string('display_name')->nullable()->after('username');
            
            // Discord OAuth2 fields
            $table->string('discord_user_id')->nullable()->unique()->after('display_name');
            $table->string('discord_username')->nullable()->after('discord_user_id');
            $table->string('discord_avatar')->nullable()->after('discord_username');
            $table->timestamp('discord_connected_at')->nullable()->after('discord_avatar');
            
            // Seller flag (default false)
            $table->boolean('is_seller')->default(false)->after('discord_connected_at');
            
            // Index for faster lookups
            $table->index('discord_user_id');
            $table->index('is_seller');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['discord_user_id']);
            $table->dropIndex(['is_seller']);
            $table->dropColumn([
                'display_name',
                'discord_user_id',
                'discord_username',
                'discord_avatar',
                'discord_connected_at',
                'is_seller',
            ]);
        });
    }
};

