<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Don't override CORS headers - let Laravel handle them
        // Only set security headers that don't conflict with CORS
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        if (config('app.env') === 'production') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            // CSP allows GTM and necessary external resources
            // Note: CSP connect-src must allow the frontend domain for API calls
            $frontendUrl = config('app.frontend_url', 'https://nxoland.com');
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' {$frontendUrl} https://restpilot.paylink.sa https://restapi.paylink.sa https://api.paylink.sa https://withpersona.com https://www.google-analytics.com https://www.googletagmanager.com;");
        }

        return $response;
    }
}

