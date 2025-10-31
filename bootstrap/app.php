<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS middleware must be registered explicitly for API routes
        // Note: EnsureFrontendRequestsAreStateful is removed because we're using Bearer tokens, not cookies
        // For Bearer token authentication, CSRF protection is not needed
        $middleware->api(prepend: [
            \App\Http\Middleware\HandleCors::class,
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, // Removed - using Bearer tokens, not cookies
        ]);
        
        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // Rate limiting - throttleApi() requires a rate limiter named 'api' to be defined
        // See withRateLimiting() below for rate limiter definition
        $middleware->throttleApi();
        
        // Trust proxies for HTTPS (required for CORS to work correctly behind proxies)
        $middleware->trustProxies(at: '*');
        
        // Security headers (must come after CORS to not interfere)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
