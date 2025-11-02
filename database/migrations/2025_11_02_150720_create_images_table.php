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
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_id')->unique(); // Cloudflare image ID
            $table->string('filename')->nullable();
            $table->text('url'); // Full delivery URL
            $table->json('meta')->nullable(); // Additional metadata (width, height, etc.)
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('image_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
