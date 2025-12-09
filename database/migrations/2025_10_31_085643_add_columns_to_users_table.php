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
            $table->string('phone')->nullable()->after('email');
            $table->enum('role', ['user', 'admin'])->default('user')->after('phone');
            $table->boolean('is_verified')->default(false)->after('role');
            $table->string('avatar')->nullable()->after('is_verified');
            $table->text('bio')->nullable()->after('avatar');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'role', 'is_verified', 'avatar', 'bio', 'deleted_at']);
        });
    }
};
