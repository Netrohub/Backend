<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use App\Http\Controllers\MessageHelper;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min(8)
                        ->mixedCase()       // Requires uppercase and lowercase
                        ->numbers()         // Requires at least one number
                        ->symbols()         // Requires at least one special character
                        ->uncompromised()   // Check against data breaches
                ],
                'phone' => 'nullable|string|max:20',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']), // Store email in lowercase
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
            ]);

            // Create wallet for new user if it doesn't exist
            if (!$user->wallet) {
                Wallet::create([
                    'user_id' => $user->id,
                    'available_balance' => 0,
                    'on_hold_balance' => 0,
                    'withdrawn_total' => 0,
                ]);
            }

            // Send email verification notification
            $user->sendEmailVerificationNotification();

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'user' => $user->load('wallet'),
                'token' => $token,
                'message' => 'تم إنشاء الحساب بنجاح. تحقق من بريدك الإلكتروني لتفعيل الحساب.',
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Registration error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            // Provide more specific error message without exposing sensitive details
            $errorCode = 'REGISTRATION_FAILED';
            $userMessage = MessageHelper::REGISTRATION_ERROR;
            
            // Check for common error types to provide better feedback
            if (str_contains($e->getMessage(), 'SQLSTATE') || str_contains($e->getMessage(), 'database')) {
                $errorCode = 'DATABASE_ERROR';
                $userMessage = 'Unable to complete registration. Please try again later.';
            } elseif (str_contains($e->getMessage(), 'email') || str_contains($e->getMessage(), 'unique')) {
                $errorCode = 'EMAIL_EXISTS';
                // This should be caught by validation, but handle gracefully
            }
            
            return response()->json([
                'message' => $userMessage,
                'error_code' => $errorCode,
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // Trim and lowercase email (mobile keyboards capitalize first letter)
            $email = strtolower(trim($request->email));
            $password = $request->password; // Don't trim password!

            // Debug logging (remove after testing)
            Log::info('Login attempt', [
                'email' => $email,
                'original_email' => $request->email,
                'password_length' => strlen($password),
                'has_leading_space' => $password !== ltrim($password),
                'has_trailing_space' => $password !== rtrim($password),
            ]);

            $user = User::where('email', $email)->first();

            if (!$user || !Hash::check($password, $user->password)) {
                Log::warning('Login failed', [
                    'email' => $email,
                    'user_exists' => !!$user,
                    'password_length' => strlen($password),
                ]);
                throw ValidationException::withMessages([
                    'email' => [MessageHelper::AUTH_INVALID_CREDENTIALS],
                ]);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'user' => $user->load('wallet'),
                'token' => $token,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            // Provide more specific error message without exposing sensitive details
            $errorCode = 'LOGIN_FAILED';
            $userMessage = MessageHelper::LOGIN_ERROR;
            
            // Check for common error types to provide better feedback
            if (str_contains($e->getMessage(), 'SQLSTATE') || str_contains($e->getMessage(), 'database')) {
                $errorCode = 'DATABASE_ERROR';
                $userMessage = 'Unable to complete login. Please try again later.';
            }
            
            return response()->json([
                'message' => $userMessage,
                'error_code' => $errorCode,
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function user(Request $request)
    {
        $user = $request->user()->load(['wallet', 'kycVerification']);
        
        return response()->json($user);
    }

    /**
     * Get user statistics for profile page
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'total_sales' => $user->ordersAsSeller()->where('status', 'completed')->count(),
            'total_purchases' => $user->ordersAsBuyer()->where('status', 'completed')->count(),
            'total_revenue' => (float) ($user->ordersAsSeller()->where('status', 'completed')->sum('amount') ?? 0),
            'active_listings' => $user->listings()->where('status', 'active')->count(),
            'total_listings' => $user->listings()->count(),
            'member_since' => $user->created_at->format('Y-m-d'),
            'average_rating' => $user->average_rating,
            'total_reviews' => $user->total_reviews,
        ]);
    }

    /**
     * Get user recent activity
     */
    public function activity(Request $request)
    {
        $user = $request->user();
        
        $activities = \App\Models\AuditLog::where('user_id', $user->id)
            ->whereIn('action', [
                'order.created',
                'order.completed',
                'listing.created',
                'kyc.verified',
                'withdrawal.completed',
            ])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'type' => str_replace('.', '_', $log->action),
                    'title' => $this->formatActivityTitle($log->action, $log->metadata),
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

        return response()->json($activities);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $request->user()->id,
            'phone' => 'sometimes|nullable|string|max:20',
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json($user->load(['wallet', 'kycVerification']));
    }

    /**
     * Update user avatar
     */
    public function updateAvatar(Request $request)
    {
        $validated = $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:2048', // Max 2MB
        ]);

        $user = $request->user();
        $file = $request->file('avatar');

        if (!$file || !$file->isValid()) {
            return response()->json([
                'message' => 'Invalid file uploaded',
            ], 400);
        }

        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');
        $accountHash = config('services.cloudflare.account_hash');

        // Validate Cloudflare configuration
        if (!$accountId || !$apiToken || !$accountHash) {
            Log::error('Cloudflare Images configuration missing for avatar upload', [
                'user_id' => $user->id,
                'has_account_id' => !empty($accountId),
                'has_api_token' => !empty($apiToken),
                'has_account_hash' => !empty($accountHash),
            ]);
            
            return response()->json([
                'message' => 'Image service configuration error',
                'error_code' => 'CLOUDFLARE_CONFIG_MISSING',
            ], 500);
        }

        try {
            Log::info('Uploading avatar to Cloudflare Images', [
                'user_id' => $user->id,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);

            // Upload to Cloudflare Images API
            $response = \Illuminate\Support\Facades\Http::withToken($apiToken)
                ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/images/v1");

            if (!$response->successful()) {
                $errorBody = $response->json();
                Log::error('Cloudflare Images upload failed', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'error' => $errorBody,
                ]);
                
                return response()->json([
                    'message' => 'Failed to upload avatar',
                    'error' => $errorBody['errors'][0]['message'] ?? 'Unknown error',
                ], $response->status());
            }

            $responseData = $response->json();
            
            if (!isset($responseData['result']['id'])) {
                Log::error('Cloudflare Images response missing image ID', [
                    'user_id' => $user->id,
                    'response' => $responseData,
                ]);
                
                return response()->json([
                    'message' => 'Invalid response from image service',
                ], 500);
            }

            $imageId = $responseData['result']['id'];
            $avatarUrl = "https://imagedelivery.net/{$accountHash}/{$imageId}/avatar";

            // Update user avatar
            $user->update(['avatar' => $avatarUrl]);

            Log::info('Avatar updated successfully', [
                'user_id' => $user->id,
                'avatar_url' => $avatarUrl,
            ]);

            return response()->json([
                'message' => 'Avatar updated successfully',
                'avatar' => $avatarUrl,
                'user' => $user->load(['wallet', 'kycVerification']),
            ]);

        } catch (\Exception $e) {
            Log::error('Avatar upload exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to upload avatar',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update password
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->mixedCase()       // Requires uppercase and lowercase
                    ->numbers()         // Requires at least one number
                    ->symbols()         // Requires at least one special character
                    ->uncompromised()   // Check against data breaches
            ],
        ]);

        $user = $request->user();

        // Check for too many failed attempts
        $cacheKey = "password_change_attempts:{$user->id}";
        $attempts = Cache::get($cacheKey, 0);
        
        if ($attempts >= 5) {
            $expiresAt = Cache::get($cacheKey . ':expires_at');
            $minutesRemaining = $expiresAt ? now()->diffInMinutes($expiresAt) : 15;
            
            return response()->json([
                'message' => "محاولات كثيرة جداً. يرجى المحاولة مرة أخرى بعد {$minutesRemaining} دقيقة",
                'error_code' => 'TOO_MANY_ATTEMPTS',
                'retry_after' => $minutesRemaining,
            ], 429);
        }

        // Verify current password
        if (!Hash::check($validated['current_password'], $user->password)) {
            // Increment failed attempts
            $newAttempts = $attempts + 1;
            $expiresAt = now()->addMinutes(15);
            Cache::put($cacheKey, $newAttempts, $expiresAt);
            Cache::put($cacheKey . ':expires_at', $expiresAt, $expiresAt);
            
            return response()->json([
                'message' => 'كلمة المرور الحالية غير صحيحة',
                'error_code' => 'INVALID_CURRENT_PASSWORD',
                'attempts_remaining' => max(0, 5 - $newAttempts),
            ], 400);
        }

        // Clear failed attempts on success
        Cache::forget($cacheKey);
        Cache::forget($cacheKey . ':expires_at');

        // Update password
        $user->password = Hash::make($validated['password']);
        $user->save();

        // CRITICAL: Revoke all tokens except current one
        $currentToken = $request->user()->currentAccessToken();
        $revokedCount = $user->tokens()->where('id', '!=', $currentToken->id)->delete();

        // Log the password change for audit trail
        \App\Models\AuditLog::create([
            'user_id' => $user->id,
            'action' => 'password.changed',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'initiated_by' => 'user',
                'tokens_revoked' => $revokedCount,
            ],
        ]);

        // Send email notification
        try {
            $user->notify(new \App\Notifications\PasswordChanged(
                now()->toDateTimeString(),
                $request->ip(),
                $request->userAgent()
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to send password change notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the password change if email fails
        }

        return response()->json([
            'message' => 'تم تحديث كلمة المرور بنجاح. تم تسجيل الخروج من جميع الأجهزة الأخرى.',
            'tokens_revoked' => $revokedCount,
        ]);
    }

    private function formatActivityTitle($action, $metadata)
    {
        $titles = [
            'order.created' => 'طلب جديد #' . ($metadata['order_id'] ?? ''),
            'order.completed' => 'تم إكمال طلب #' . ($metadata['order_id'] ?? ''),
            'listing.created' => 'تم نشر إعلان جديد',
            'kyc.verified' => 'تم توثيق الحساب',
            'withdrawal.completed' => 'تم سحب $' . ($metadata['amount'] ?? '0'),
        ];

        return $titles[$action] ?? $action;
    }

    /**
     * Send email verification notification
     */
    public function sendVerificationEmail(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'البريد الإلكتروني موثق بالفعل'
            ], 400);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'تم إرسال رسالة التحقق إلى بريدك الإلكتروني'
        ]);
    }

    /**
     * Mark the authenticated user's email address as verified
     */
    public function verifyEmail(Request $request)
    {
        $user = User::findOrFail($request->route('id'));

        if (!hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            return response()->json([
                'message' => 'رابط التحقق غير صالح'
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'البريد الإلكتروني موثق بالفعل',
                'verified' => true
            ]);
        }

        $user->markEmailAsVerified();

        return response()->json([
            'message' => 'تم توثيق البريد الإلكتروني بنجاح',
            'verified' => true
        ]);
    }
}
