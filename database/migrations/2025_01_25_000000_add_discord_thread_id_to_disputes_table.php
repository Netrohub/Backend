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
        Schema::table('disputes', function (Blueprint $table) {
            $table->string('discord_thread_id')->nullable()->after('status');
            $table->string('discord_channel_id')->nullable()->after('discord_thread_id');
            $table->index('discord_thread_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropIndex(['discord_thread_id']);
            $table->dropColumn(['discord_thread_id', 'discord_channel_id']);
        });
    }
};

