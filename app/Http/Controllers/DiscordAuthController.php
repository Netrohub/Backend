<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use App\Http\Controllers\MessageHelper;

class DiscordAuthController extends Controller
{
    /**
     * Redirect user to Discord OAuth2 authorization
     * 
     * @param Request $request
     * @param string $mode 'login' or 'connect'
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirect(Request $request, string $mode = 'login')
    {
        $request->validate([
            'mode' => 'sometimes|in:login,connect',
        ]);
        
        $mode = $request->input('mode', $mode);
        
        // For 'connect' mode, user must be authenticated
        if ($mode === 'connect' && !$request->user()) {
            return response()->json([
                'error' => 'authentication_required',
                'message' => 'You must be logged in to connect your Discord account.',
            ], 401);
        }
        
        $clientId = config('services.discord.client_id');
        $redirectUri = config('services.discord.redirect_uri');
        $scopes = config('services.discord.scopes', 'identify email');
        
        if (!$clientId || !$redirectUri) {
            Log::error('Discord OAuth configuration missing', [
                'has_client_id' => !empty($clientId),
                'has_redirect_uri' => !empty($redirectUri),
            ]);
            
            return response()->json([
                'error' => 'discord_config_missing',
                'message' => 'Discord OAuth is not properly configured.',
            ], 500);
        }
        
        $state = bin2hex(random_bytes(16));
        $redirectTo = $request->query('redirect_to', '/');
        
        // Store state in cache for 10 minutes (same as TikTok OAuth)
        Cache::put("discord_oauth_state:{$state}", [
            'state' => $state,
            'mode' => $mode,
            'redirect_to' => $redirectTo,
        ], now()->addMinutes(10));
        
        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scopes,
            'state' => $state,
        ]);
        
        $authUrl = "https://discord.com/api/oauth2/authorize?{$params}";
        
        return redirect($authUrl);
    }

    /**
     * Handle Discord OAuth2 callback
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        
        // Handle OAuth errors
        if ($error) {
            Log::warning('Discord OAuth error', ['error' => $error]);
            return redirect(config('app.frontend_url') . '/auth?error=discord_oauth_failed');
        }
        
        // Verify state from cache
        $stateData = Cache::get("discord_oauth_state:{$state}");
        
        if (!$stateData || !isset($stateData['state']) || $stateData['state'] !== $state) {
            Log::warning('Discord OAuth state mismatch or expired', [
                'provided' => $state,
                'cached' => $stateData ? 'exists' : 'missing',
            ]);
            return redirect(config('app.frontend_url') . '/auth?error=invalid_state');
        }
        
        if (!$code) {
            return redirect(config('app.frontend_url') . '/auth?error=no_code');
        }
        
        $mode = $stateData['mode'] ?? 'login';
        $redirectTo = $stateData['redirect_to'] ?? '/';
        
        // Clear state from cache
        Cache::forget("discord_oauth_state:{$state}");
        
        try {
            // Exchange code for access token
            $tokenResponse = Http::asForm()->post('https://discord.com/api/oauth2/token', [
                'client_id' => config('services.discord.client_id'),
                'client_secret' => config('services.discord.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.discord.redirect_uri'),
            ]);
            
            if (!$tokenResponse->successful()) {
                Log::error('Discord token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->body(),
                ]);
                return redirect(config('app.frontend_url') . '/auth?error=token_exchange_failed');
            }
            
            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'] ?? null;
            
            if (!$accessToken) {
                return redirect(config('app.frontend_url') . '/auth?error=no_access_token');
            }
            
            // Get user info from Discord
            $userResponse = Http::withToken($accessToken)
                ->get('https://discord.com/api/users/@me');
            
            if (!$userResponse->successful()) {
                Log::error('Discord user info fetch failed', [
                    'status' => $userResponse->status(),
                    'body' => $userResponse->body(),
                ]);
                return redirect(config('app.frontend_url') . '/auth?error=user_info_failed');
            }
            
            $discordUser = $userResponse->json();
            $discordUserId = $discordUser['id'] ?? null;
            $discordUsername = ($discordUser['username'] ?? '') . '#' . ($discordUser['discriminator'] ?? '0000');
            $discordAvatar = $discordUser['avatar'] 
                ? "https://cdn.discordapp.com/avatars/{$discordUserId}/{$discordUser['avatar']}.png"
                : null;
            $discordEmail = $discordUser['email'] ?? null;
            
            if (!$discordUserId) {
                return redirect(config('app.frontend_url') . '/auth?error=invalid_discord_user');
            }
            
            // Handle login vs connect mode
            if ($mode === 'connect') {
                return $this->handleConnect($request, $discordUserId, $discordUsername, $discordAvatar);
            } else {
                return $this->handleLogin($request, $discordUserId, $discordUsername, $discordAvatar, $discordEmail);
            }
            
        } catch (\Exception $e) {
            Log::error('Discord OAuth callback exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect(config('app.frontend_url') . '/auth?error=oauth_exception');
        }
    }

    /**
     * Handle login mode - log in existing user or create new one
     */
    private function handleLogin(Request $request, string $discordUserId, string $discordUsername, ?string $discordAvatar, ?string $discordEmail)
    {
        // Check if Discord account is already linked
        $user = User::where('discord_user_id', $discordUserId)->first();
        
        if ($user) {
            // User exists - log them in
            $token = $user->createToken('discord-auth-token')->plainTextToken;
            
            return redirect(config('app.frontend_url') . '/auth/discord/callback?token=' . urlencode($token) . '&mode=login');
        }
        
        // New user - create account
        // Try to find by email if provided
        if ($discordEmail) {
            $existingUser = User::where('email', strtolower($discordEmail))->first();
            if ($existingUser) {
                // Check if Discord ID is already linked to another user
                $discordLinkedUser = User::where('discord_user_id', $discordUserId)
                    ->where('id', '!=', $existingUser->id)
                    ->first();
                
                if ($discordLinkedUser) {
                    Log::warning('Discord account already linked to another user', [
                        'discord_user_id' => $discordUserId,
                        'existing_user_id' => $discordLinkedUser->id,
                        'attempted_user_id' => $existingUser->id,
                    ]);
                    return redirect(config('app.frontend_url') . '/auth?error=discord_already_linked');
                }
                
                // Link Discord to existing email account
                $existingUser->update([
                    'discord_user_id' => $discordUserId,
                    'discord_username' => $discordUsername,
                    'discord_avatar' => $discordAvatar,
                    'discord_connected_at' => now(),
                ]);
                
                $token = $existingUser->createToken('discord-auth-token')->plainTextToken;
                return redirect(config('app.frontend_url') . '/auth/discord/callback?token=' . urlencode($token) . '&mode=login');
            }
        }
        
        // Double-check Discord ID isn't already linked (race condition protection)
        $existingDiscordUser = User::where('discord_user_id', $discordUserId)->first();
        if ($existingDiscordUser) {
            // Discord already linked - log them in instead
            $token = $existingDiscordUser->createToken('discord-auth-token')->plainTextToken;
            return redirect(config('app.frontend_url') . '/auth/discord/callback?token=' . urlencode($token) . '&mode=login');
        }
        
        // Create new user
        $username = User::generateUsername($discordUsername);
        $email = $discordEmail ?? "discord_{$discordUserId}@nxoland.local"; // Placeholder email
        
        // Generate random password (user can set it later if needed)
        $password = Hash::make(bin2hex(random_bytes(32)));
        
        try {
            $user = User::create([
                'name' => $discordUsername,
                'username' => $username,
                'display_name' => $discordUsername,
                'email' => $email,
                'password' => $password,
                'discord_user_id' => $discordUserId,
                'discord_username' => $discordUsername,
                'discord_avatar' => $discordAvatar,
                'discord_connected_at' => now(),
                'email_verified_at' => $discordEmail ? now() : null, // Auto-verify if email from Discord
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle unique constraint violation (race condition)
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'discord_user_id')) {
                Log::warning('Discord user ID unique constraint violation during user creation', [
                    'discord_user_id' => $discordUserId,
                    'error' => $e->getMessage(),
                ]);
                
                // Try to find and log in the existing user
                $existingUser = User::where('discord_user_id', $discordUserId)->first();
                if ($existingUser) {
                    $token = $existingUser->createToken('discord-auth-token')->plainTextToken;
                    return redirect(config('app.frontend_url') . '/auth/discord/callback?token=' . urlencode($token) . '&mode=login');
                }
            }
            
            throw $e;
        }
        
        // Create wallet for new user
        if (!$user->wallet) {
            Wallet::create([
                'user_id' => $user->id,
                'available_balance' => 0,
                'on_hold_balance' => 0,
                'withdrawn_total' => 0,
            ]);
        }
        
        $token = $user->createToken('discord-auth-token')->plainTextToken;
        
        return redirect(config('app.frontend_url') . '/auth/discord/callback?token=' . urlencode($token) . '&mode=login');
    }

    /**
     * Handle connect mode - link Discord to existing authenticated user
     */
    private function handleConnect(Request $request, string $discordUserId, string $discordUsername, ?string $discordAvatar)
    {
        $user = $request->user();
        
        if (!$user) {
            return redirect(config('app.frontend_url') . '/auth?error=not_authenticated');
        }
        
        // Check if Discord ID is already linked to another user
        $existingUser = User::where('discord_user_id', $discordUserId)
            ->where('id', '!=', $user->id)
            ->first();
        
        if ($existingUser) {
            Log::warning('Attempt to link Discord account already linked to another user', [
                'discord_user_id' => $discordUserId,
                'existing_user_id' => $existingUser->id,
                'attempted_user_id' => $user->id,
            ]);
            return redirect(config('app.frontend_url') . '/settings?error=discord_already_linked');
        }
        
        // Link Discord to current user (with error handling for race conditions)
        try {
            $user->update([
                'discord_user_id' => $discordUserId,
                'discord_username' => $discordUsername,
                'discord_avatar' => $discordAvatar,
                'discord_connected_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle unique constraint violation (race condition)
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'discord_user_id')) {
                Log::warning('Discord user ID unique constraint violation during connect', [
                    'discord_user_id' => $discordUserId,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                return redirect(config('app.frontend_url') . '/settings?error=discord_already_linked');
            }
            throw $e;
        }
        
        return redirect(config('app.frontend_url') . '/settings?discord_connected=true');
    }

    /**
     * Disconnect Discord account
     */
    public function disconnect(Request $request)
    {
        $user = $request->user();
        
        if (!$user->hasDiscord()) {
            return response()->json([
                'error' => 'discord_not_connected',
                'message' => 'Discord account is not connected.',
            ], 400);
        }
        
        $user->update([
            'discord_user_id' => null,
            'discord_username' => null,
            'discord_avatar' => null,
            'discord_connected_at' => null,
        ]);
        
        return response()->json([
            'message' => 'Discord account disconnected successfully.',
        ]);
    }
}

