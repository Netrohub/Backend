<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
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
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(self https://inquiry.withpersona.com), camera=(self https://inquiry.withpersona.com)');

        if (!App::environment('local', 'testing')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            // CSP allows GTM and necessary external resources
            // Note: CSP connect-src must allow the frontend domain for API calls
            $frontendUrl = config('app.frontend_url', 'https://nxoland.com');
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; frame-src https://withpersona.com https://inquiry.withpersona.com https://challenges.cloudflare.com https://www.googletagmanager.com https://docs.plasmic.app https://studio.plasmic.app https://js.stripe.com; connect-src 'self' {$frontendUrl} https://restpilot.paylink.sa https://restapi.paylink.sa https://api.paylink.sa https://withpersona.com https://inquiry.withpersona.com https://www.google-analytics.com https://www.googletagmanager.com; child-src https://inquiry.withpersona.com https://challenges.cloudflare.com; frame-ancestors 'self' https://studio.plasmic.app https://docs.plasmic.app;"
            );
        }

        return $response;
    }
}

