<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\MessageHelper;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
                'phone' => 'nullable|string|max:20',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
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

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'user' => $user->load('wallet'),
                'token' => $token,
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

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
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
            'total_revenue' => (float) ($user->ordersAsSeller()->where('status', 'completed')->sum('total_price') ?? 0),
            'active_listings' => $user->listings()->where('status', 'active')->count(),
            'total_listings' => $user->listings()->count(),
            'member_since' => $user->created_at->format('Y-m-d'),
        ]);
    }
}
