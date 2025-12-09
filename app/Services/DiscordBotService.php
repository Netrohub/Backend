<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordBotService
{
    private ?string $webhookUrl;
    private ?string $webhookSecret;
    private ?string $frontendUrl;

    public function __construct()
    {
        $this->webhookUrl = config('services.discord_bot.webhook_url');
        $this->webhookSecret = config('services.discord_bot.webhook_secret');
        $this->frontendUrl = config('app.frontend_url', 'http://localhost:5173');
    }

    /**
     * Send a new listing notification to Discord bot
     * 
     * @param array $listing Listing data
     * @return array|null Response data or null if disabled/failed
     */
    public function notifyNewListing(array $listing): ?array
    {
        // Skip if webhook URL is not configured
        if (!$this->webhookUrl) {
            return null;
        }

        try {
            // Use new unified event format
            // Remove /webhook/listing suffix if present, use /webhook instead
            $webhookUrl = $this->webhookUrl;
            if (str_ends_with($webhookUrl, '/webhook/listing')) {
                $webhookUrl = str_replace('/webhook/listing', '/webhook', $webhookUrl);
            } elseif (!str_ends_with($webhookUrl, '/webhook')) {
                // If URL doesn't end with /webhook, append it
                $webhookUrl = rtrim($webhookUrl, '/') . '/webhook';
            }

            $payload = [
                'event_type' => 'listing.created',
                'data' => [
                    'listing_id' => $listing['id'],
                    'id' => $listing['id'], // Backward compatibility
                    'title' => $listing['title'],
                    'description' => $listing['description'] ?? null,
                    'price' => (string) $listing['price'],
                    'category' => $listing['category'] ?? null,
                    'images' => $listing['images'] ?? [],
                    'created_at' => $listing['created_at'] ?? now()->toIso8601String(),
                ],
                'timestamp' => now()->toIso8601String(),
            ];

            $headers = [
                'Content-Type' => 'application/json',
            ];

            // Add webhook secret header if configured
            if ($this->webhookSecret) {
                $headers['X-Webhook-Secret'] = $this->webhookSecret;
            }

            Log::info('Discord Bot Webhook Request', [
                'url' => $webhookUrl,
                'listing_id' => $listing['id'],
            ]);

            $response = Http::timeout(5)
                ->withHeaders($headers)
                ->post($webhookUrl, $payload);

            $responseData = $response->json();

            Log::info('Discord Bot Webhook Response', [
                'status_code' => $response->status(),
                'listing_id' => $listing['id'],
                'response' => $responseData,
                'success' => $response->successful(),
            ]);

            if (!$response->successful()) {
                Log::error('Discord Bot Webhook Failed', [
                    'status' => $response->status(),
                    'listing_id' => $listing['id'],
                    'error' => $responseData,
                ]);
            }

            return $responseData;
        } catch (\Exception $e) {
            Log::error('Discord Bot Webhook Exception', [
                'listing_id' => $listing['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

