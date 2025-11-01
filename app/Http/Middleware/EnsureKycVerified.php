<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKycVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Check if user has KYC verification with verified status
        if (!$user->is_verified) {
            return response()->json([
                'message' => 'KYC verification is required. Please complete identity verification first.',
                'error_code' => 'KYC_NOT_VERIFIED',
            ], 403);
        }

        return $next($request);
    }
}

