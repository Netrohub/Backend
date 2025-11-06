<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokVerificationService
{
    private string $clientKey;
    private string $clientSecret;
    private string $baseUrl;

    public function __construct()
    {
        $this->clientKey = config('services.tiktok.client_key');
        $this->clientSecret = config('services.tiktok.client_secret');
        $this->baseUrl = config('services.tiktok.base_url', 'https://open-api.tiktok.com');
    }

    /**
     * Get user's profile information including bio
     * 
     * @param string $accessToken User's TikTok access token
     * @return array|null User profile data or null on failure
     */
    public function getUserProfile(string $accessToken): ?array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/user/info/', [
                'access_token' => $accessToken,
                'fields' => [
                    'open_id',
                    'union_id',
                    'avatar_url',
                    'display_name',
                    'bio_description',  // This is what we need!
                    'username',
                    'is_verified',
                ],
            ]);

            if (!$response->successful()) {
                Log::error('TikTok API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            if (isset($data['error']) && $data['error']['code'] !== 0) {
                Log::error('TikTok API returned error', [
                    'error' => $data['error'],
                ]);
                return null;
            }

            return $data['data']['user'] ?? null;
        } catch (\Exception $e) {
            Log::error('TikTok API exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verify that the verification code exists in user's bio
     * 
     * @param string $accessToken User's TikTok access token
     * @param string $verificationCode The code that should be in their bio
     * @return bool True if code is found in bio, false otherwise
     */
    public function verifyBioCode(string $accessToken, string $verificationCode): bool
    {
        $profile = $this->getUserProfile($accessToken);

        if (!$profile) {
            return false;
        }

        $bioDescription = $profile['bio_description'] ?? '';

        // Check if verification code exists in bio
        // Case-insensitive search, trim whitespace
        $bioDescription = strtolower(trim($bioDescription));
        $verificationCode = strtolower(trim($verificationCode));

        $found = str_contains($bioDescription, $verificationCode);

        Log::info('TikTok bio verification', [
            'code' => $verificationCode,
            'bio_contains_code' => $found,
            'username' => $profile['username'] ?? 'unknown',
        ]);

        return $found;
    }

    /**
     * Generate OAuth authorization URL
     * 
     * @param string $redirectUri Where to redirect after authorization
     * @param string $state CSRF protection state parameter
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'client_key' => $this->clientKey,
            'scope' => 'user.info.basic,user.info.profile',
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return 'https://www.tiktok.com/v2/auth/authorize/?' . $params;
    }

    /**
     * Exchange authorization code for access token
     * 
     * @param string $code Authorization code from OAuth callback
     * @return array|null Token data or null on failure
     */
    public function getAccessToken(string $code): ?array
    {
        try {
            $response = Http::post('https://open-api.tiktok.com/oauth/access_token/', [
                'client_key' => $this->clientKey,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
            ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            if (isset($data['error']) && $data['error']['code'] !== 0) {
                Log::error('TikTok token error', ['error' => $data['error']]);
                return null;
            }

            return $data['data'] ?? null;
        } catch (\Exception $e) {
            Log::error('TikTok token exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

