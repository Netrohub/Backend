<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SiteSettingController extends Controller
{
    /**
     * Get a specific site setting
     */
    public function show(string $key)
    {
        $setting = SiteSetting::where('key', $key)->first();
        
        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found'
            ], 404);
        }

        return response()->json([
            'data' => [
                'key' => $setting->key,
                'value_ar' => $setting->value_ar,
                'value_en' => $setting->value_en,
                'type' => $setting->type,
                'updated_at' => $setting->updated_at,
            ]
        ]);
    }

    /**
     * Get all site settings (admin only)
     */
    public function index()
    {
        $settings = SiteSetting::all();

        return response()->json([
            'data' => $settings
        ]);
    }

    /**
     * Update a site setting (admin only)
     */
    public function update(Request $request, string $key)
    {
        $validator = Validator::make($request->all(), [
            'value_ar' => 'nullable|string',
            'value_en' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $setting = SiteSetting::where('key', $key)->first();
        
        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found'
            ], 404);
        }

        $setting->update([
            'value_ar' => $request->input('value_ar', $setting->value_ar),
            'value_en' => $request->input('value_en', $setting->value_en),
        ]);

        // Clear cache
        SiteSetting::clearCache();

        return response()->json([
            'message' => 'Setting updated successfully',
            'data' => [
                'key' => $setting->key,
                'value_ar' => $setting->value_ar,
                'value_en' => $setting->value_en,
                'type' => $setting->type,
                'updated_at' => $setting->updated_at,
            ]
        ]);
    }
}

