<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $fillable = [
        'key',
        'value_ar',
        'value_en',
        'type',
        'description',
    ];

    /**
     * Get a setting value by key and language
     */
    public static function get(string $key, string $language = 'ar', $default = null)
    {
        $cacheKey = "site_setting_{$key}_{$language}";
        
        return Cache::remember($cacheKey, 3600, function () use ($key, $language, $default) {
            $setting = self::where('key', $key)->first();
            
            if (!$setting) {
                return $default;
            }
            
            $field = $language === 'en' ? 'value_en' : 'value_ar';
            return $setting->{$field} ?? $default;
        });
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, $valueAr = null, $valueEn = null, string $type = 'text'): self
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'value_ar' => $valueAr,
                'value_en' => $valueEn,
                'type' => $type,
            ]
        );

        // Clear cache
        Cache::forget("site_setting_{$key}_ar");
        Cache::forget("site_setting_{$key}_en");

        return $setting;
    }

    /**
     * Clear all setting caches
     */
    public static function clearCache(): void
    {
        Cache::flush();
    }
}

