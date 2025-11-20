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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // Setting key (e.g., 'site_name', 'maintenance_mode')
            $table->text('value')->nullable(); // Setting value
            $table->string('type')->default('string'); // string, boolean, number, json
            $table->string('group')->default('general'); // general, security, notifications, payments
            $table->text('description')->nullable(); // Description of what this setting does
            $table->timestamps();
            
            // Index for quick lookups
            $table->index('key');
            $table->index('group');
        });
        
        // Seed default settings
        DB::table('settings')->insert([
            [
                'key' => 'site_name',
                'value' => 'NXOLand',
                'type' => 'string',
                'group' => 'general',
                'description' => 'اسم المنصة',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'site_description',
                'value' => 'منصة آمنة لبيع وشراء حسابات الألعاب',
                'type' => 'string',
                'group' => 'general',
                'description' => 'وصف المنصة',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'maintenance_mode',
                'value' => 'false',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'وضع الصيانة',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'escrow_hold_hours',
                'value' => '72',
                'type' => 'number',
                'group' => 'security',
                'description' => 'ساعات حجز الضمان',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'min_withdrawal_amount',
                'value' => '100',
                'type' => 'number',
                'group' => 'payments',
                'description' => 'الحد الأدنى للسحب (ريال)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'platform_fee_percentage',
                'value' => '5',
                'type' => 'number',
                'group' => 'payments',
                'description' => 'نسبة عمولة المنصة (%)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'withdrawal_fee_percentage',
                'value' => '5',
                'type' => 'number',
                'group' => 'payments',
                'description' => 'نسبة رسوم السحب (%) - إذا لم يتم تحديدها، سيتم استخدام نسبة عمولة المنصة',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
