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
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => MessageHelper::ERROR_SERVER_ERROR,
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
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => MessageHelper::ERROR_SERVER_ERROR,
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
}
