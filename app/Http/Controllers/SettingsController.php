<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    /**
     * Get all settings (admin only)
     */
    public function index()
    {
        $settings = Cache::remember('settings_all', 3600, function () {
            return DB::table('settings')
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->groupBy('group');
        });

        return response()->json($settings);
    }

    /**
     * Get a specific setting by key
     */
    public function show($key)
    {
        $setting = Cache::remember("setting_{$key}", 3600, function () use ($key) {
            return DB::table('settings')->where('key', $key)->first();
        });

        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found',
            ], 404);
        }

        // Parse value based on type
        $value = $this->parseValue($setting->value, $setting->type);

        return response()->json([
            'key' => $setting->key,
            'value' => $value,
            'type' => $setting->type,
            'group' => $setting->group,
            'description' => $setting->description,
        ]);
    }

    /**
     * Update a setting (admin only)
     */
    public function update(Request $request, $key)
    {
        $setting = DB::table('settings')->where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found',
            ], 404);
        }

        $validated = $request->validate([
            'value' => 'required',
        ]);

        // Convert value to string for storage
        $value = $this->stringifyValue($validated['value'], $setting->type);

        DB::table('settings')
            ->where('key', $key)
            ->update([
                'value' => $value,
                'updated_at' => now(),
            ]);

        // Clear cache
        Cache::forget("setting_{$key}");
        Cache::forget('settings_all');

        return response()->json([
            'message' => 'Setting updated successfully',
            'key' => $key,
            'value' => $validated['value'],
        ]);
    }

    /**
     * Bulk update settings (admin only)
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|exists:settings,key',
            'settings.*.value' => 'required',
        ]);

        foreach ($validated['settings'] as $settingData) {
            $setting = DB::table('settings')->where('key', $settingData['key'])->first();
            
            if ($setting) {
                $value = $this->stringifyValue($settingData['value'], $setting->type);
                
                DB::table('settings')
                    ->where('key', $settingData['key'])
                    ->update([
                        'value' => $value,
                        'updated_at' => now(),
                    ]);

                Cache::forget("setting_{$settingData['key']}");
            }
        }

        Cache::forget('settings_all');

        return response()->json([
            'message' => 'Settings updated successfully',
        ]);
    }

    /**
     * Create a new setting (admin only)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:255|unique:settings,key',
            'value' => 'required',
            'type' => 'required|in:string,boolean,number,json',
            'group' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $value = $this->stringifyValue($validated['value'], $validated['type']);

        DB::table('settings')->insert([
            'key' => $validated['key'],
            'value' => $value,
            'type' => $validated['type'],
            'group' => $validated['group'],
            'description' => $validated['description'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget('settings_all');

        return response()->json([
            'message' => 'Setting created successfully',
        ], 201);
    }

    /**
     * Delete a setting (admin only)
     */
    public function destroy($key)
    {
        $setting = DB::table('settings')->where('key', $key)->first();

        if (!$setting) {
            return response()->json([
                'message' => 'Setting not found',
            ], 404);
        }

        DB::table('settings')->where('key', $key)->delete();

        Cache::forget("setting_{$key}");
        Cache::forget('settings_all');

        return response()->json([
            'message' => 'Setting deleted successfully',
        ]);
    }

    /**
     * Parse value from string based on type
     */
    private function parseValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                return is_numeric($value) ? (strpos($value, '.') !== false ? (float)$value : (int)$value) : $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * Convert value to string for storage
     */
    private function stringifyValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return $value ? 'true' : 'false';
            case 'number':
                return (string)$value;
            case 'json':
                return is_string($value) ? $value : json_encode($value);
            default:
                return (string)$value;
        }
    }
}
