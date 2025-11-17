<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleByUser
{
    /**
     * Handle an incoming request.
     * Rate limit by authenticated user ID instead of IP address.
     * This prevents rate limit bypass using proxies/VPNs.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  int  $maxAttempts  Maximum number of attempts
     * @param  int  $decayMinutes  Number of minutes to decay
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $user = $request->user();

        // If user is authenticated, rate limit by user ID
        if ($user) {
            $key = 'user:' . $user->id . ':' . $request->path();
        } else {
            // Fallback to IP-based limiting for unauthenticated requests
            $key = 'ip:' . $request->ip() . ':' . $request->path();
        }

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            return response()->json([
                'message' => 'Too many attempts. Please try again in ' . $seconds . ' seconds.',
                'error_code' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $seconds,
            ], 429)->withHeaders([
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        $remaining = $maxAttempts - RateLimiter::attempts($key);
        
        return $response->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => max(0, $remaining),
        ]);
    }
}

