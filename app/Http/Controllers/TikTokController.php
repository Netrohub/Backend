<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TikTokVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TikTokController extends Controller
{
    public function __construct(
        private TikTokVerificationService $tiktokService
    ) {}

    /**
     * Initiate TikTok OAuth flow for verification
     * Returns authorization URL
     */
    public function authorize(Request $request)
    {
        $user = $request->user();
        
        // Generate state for CSRF protection (includes user_id)
        $state = $user->id . '_' . Str::random(40);
        
        // Store state in cache for 10 minutes
        Cache::put("tiktok_oauth_state:{$user->id}", $state, now()->addMinutes(10));
        Cache::put("tiktok_oauth_user:{$state}", $user->id, now()->addMinutes(10));
        
        // Get authorization URL
        $redirectUri = config('services.tiktok.redirect_uri');
        $authUrl = $this->tiktokService->getAuthorizationUrl($redirectUri, $state);
        
        return response()->json([
            'authorization_url' => $authUrl,
            'state' => $state,
        ]);
    }

    /**
     * Handle TikTok OAuth callback
     * Exchange code for access token
     */
    public function callback(Request $request)
    {
        $code = $request->input('code');
        $state = $request->input('state');
        
        if (!$code || !$state) {
            return redirect(config('app.frontend_url') . '/sell/social/tiktok?error=missing_params');
        }

        // Get user_id from state (we'll include it in the state parameter)
        // For now, we'll use a different approach - store user_id in cache with state
        $userId = Cache::get("tiktok_oauth_user:{$state}");
        
        if (!$userId) {
            return redirect(config('app.frontend_url') . '/sell/social/tiktok?error=invalid_state');
        }

        // Verify CSRF state
        $expectedState = Cache::get("tiktok_oauth_state:{$userId}");
        
        if ($state !== $expectedState) {
            return redirect(config('app.frontend_url') . '/sell/social/tiktok?error=invalid_state');
        }

        // Exchange code for access token
        $tokenData = $this->tiktokService->getAccessToken($code);
        
        if (!$tokenData) {
            return redirect(config('app.frontend_url') . '/sell/social/tiktok?error=token_failed');
        }

        // Store access token temporarily (1 hour - enough time to create listing)
        $accessToken = $tokenData['access_token'];
        Cache::put("tiktok_token:{$userId}", $accessToken, now()->addHour());
        
        // Get user profile to show in frontend
        $profile = $this->tiktokService->getUserProfile($accessToken);
        
        if ($profile) {
            Cache::put("tiktok_profile:{$userId}", $profile, now()->addHour());
        }

        // Redirect back to frontend with success
        return redirect(config('app.frontend_url') . '/sell/social/tiktok?connected=true&username=' . urlencode($profile['username'] ?? ''));
    }

    /**
     * Get stored TikTok profile for authenticated user
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();
        $profile = Cache::get("tiktok_profile:{$user->id}");
        
        if (!$profile) {
            return response()->json([
                'connected' => false,
                'message' => 'Not connected to TikTok',
            ], 404);
        }

        return response()->json([
            'connected' => true,
            'profile' => $profile,
        ]);
    }

    /**
     * Verify bio code for authenticated user
     */
    public function verifyBio(Request $request)
    {
        $validated = $request->validate([
            'verification_code' => 'required|string|size:7',
        ]);

        $user = $request->user();
        $accessToken = Cache::get("tiktok_token:{$user->id}");

        if (!$accessToken) {
            return response()->json([
                'verified' => false,
                'message' => 'TikTok connection expired. Please reconnect.',
                'error_code' => 'TOKEN_EXPIRED',
            ], 401);
        }

        // Verify bio contains code
        $verified = $this->tiktokService->verifyBioCode($accessToken, $validated['verification_code']);

        if ($verified) {
            // Get profile for metadata
            $profile = $this->tiktokService->getUserProfile($accessToken);
            
            return response()->json([
                'verified' => true,
                'message' => 'Bio verification successful!',
                'profile' => $profile,
            ]);
        }

        return response()->json([
            'verified' => false,
            'message' => 'Verification code not found in bio. Please add it and try again.',
            'error_code' => 'CODE_NOT_FOUND',
        ], 400);
    }

    /**
     * Disconnect TikTok (clear cached tokens)
     */
    public function disconnect(Request $request)
    {
        $user = $request->user();
        
        Cache::forget("tiktok_token:{$user->id}");
        Cache::forget("tiktok_profile:{$user->id}");
        Cache::forget("tiktok_oauth_state:{$user->id}");

        return response()->json([
            'message' => 'Disconnected from TikTok',
        ]);
    }
}

