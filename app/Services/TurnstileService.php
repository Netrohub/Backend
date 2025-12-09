<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileService
{
    private string $secret;
    private string $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct()
    {
        $this->secret = config('services.turnstile.secret_key');
    }

    /**
     * Verify Cloudflare Turnstile token
     *
     * @param string $token The turnstile token from the frontend
     * @param string|null $ip The user's IP address
     * @return bool
     */
    public function verify(string $token, ?string $ip = null): bool
    {
        // Skip verification in local development if secret is not set
        if (app()->environment('local') && !$this->secret) {
            Log::warning('Turnstile verification skipped: No secret key configured');
            return true;
        }

        if (!$this->secret) {
            Log::error('Turnstile secret key not configured');
            return false;
        }

        try {
            $response = Http::asForm()->post($this->verifyUrl, [
                'secret' => $this->secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['success']) && $data['success']) {
                return true;
            }

            Log::warning('Turnstile verification failed', [
                'error_codes' => $data['error-codes'] ?? [],
                'success' => $data['success'] ?? false,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Turnstile verification exception', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate turnstile token from request
     *
     * @param \Illuminate\Http\Request $request
     * @param string $field The field name containing the token (default: 'turnstile_token')
     * @return bool
     */
    public function verifyRequest($request, string $field = 'turnstile_token'): bool
    {
        $token = $request->input($field);
        
        if (!$token) {
            return false;
        }

        $ip = $request->ip();
        
        return $this->verify($token, $ip);
    }
}

