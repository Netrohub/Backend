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
        // Disable Laravel's default CORS handling - we use our own custom HandleCors middleware
        // This prevents conflicts between Laravel's CORS and our custom implementation
        
        // CORS middleware must be registered explicitly for API routes
        // Note: EnsureFrontendRequestsAreStateful is removed because we're using Bearer tokens, not cookies
        // For Bearer token authentication, CSRF protection is not needed
        $middleware->api(prepend: [
            \App\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\AddRequestId::class, // Add request correlation ID
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, // Removed - using Bearer tokens, not cookies
        ]);
        
        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'kycVerified' => \App\Http\Middleware\EnsureKycVerified::class,
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
        // Log all exceptions with full context
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $exception, \Illuminate\Http\Request $request) {
            if (app()->bound('log')) {
                \Illuminate\Support\Facades\Log::error('Exception occurred', [
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                ]);
            }
            return $response;
        });
    })->create();
