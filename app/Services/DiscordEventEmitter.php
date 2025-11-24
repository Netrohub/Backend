<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordEventEmitter
{
    /**
     * Send event to Discord bot webhook
     * 
     * @param string $eventType
     * @param array $data
     * @return bool
     */
    public static function emit(string $eventType, array $data): bool
    {
        $webhookUrl = config('services.discord.bot_webhook_url');
        
        if (!$webhookUrl) {
            Log::warning('Discord bot webhook URL not configured', [
                'event_type' => $eventType,
            ]);
            return false;
        }
        
        $payload = [
            'event_type' => $eventType,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ];
        
        try {
            $response = Http::timeout(10)
                ->post($webhookUrl, $payload);
            
            if (!$response->successful()) {
                Log::error('Discord webhook failed', [
                    'event_type' => $eventType,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }
            
            Log::info('Discord event emitted successfully', [
                'event_type' => $eventType,
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Discord webhook exception', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

