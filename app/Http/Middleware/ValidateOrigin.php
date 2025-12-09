<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class ValidateOrigin
{
    /**
     * Handle an incoming request.
     * Validates Origin/Referer headers for CSRF protection on state-changing operations.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only validate for state-changing methods
        $stateChangingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        
        if (!in_array($request->method(), $stateChangingMethods)) {
            return $next($request);
        }

        // Skip validation for webhooks (they come from external services)
        if ($request->is('api/v1/webhook/*')) {
            return $next($request);
        }

        // Validate origin/referer
        if (!\App\Helpers\SecurityHelper::validateOrigin($request)) {
            Log::warning('CSRF validation failed', [
                'method' => $request->method(),
                'path' => $request->path(),
                'origin' => $request->header('Origin'),
                'referer' => $request->header('Referer'),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Request origin validation failed',
                'error_code' => 'CSRF_VALIDATION_FAILED',
            ], 403);
        }

        return $next($request);
    }
}

