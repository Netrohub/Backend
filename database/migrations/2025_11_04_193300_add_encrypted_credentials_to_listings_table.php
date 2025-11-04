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
        Schema::table('listings', function (Blueprint $table) {
            // Add encrypted credential fields
            $table->text('account_email_encrypted')->nullable()->after('images');
            $table->text('account_password_encrypted')->nullable()->after('account_email_encrypted');
            $table->json('account_metadata')->nullable()->after('account_password_encrypted')->comment('Additional account data: server, stove level, helios, troops, etc.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['account_email_encrypted', 'account_password_encrypted', 'account_metadata']);
        });
    }
};
