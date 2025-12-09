<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TurnstileService;
use Symfony\Component\HttpFoundation\Response;

class VerifyTurnstile
{
    protected TurnstileService $turnstile;

    public function __construct(TurnstileService $turnstile)
    {
        $this->turnstile = $turnstile;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip verification for GET requests
        if ($request->isMethod('get')) {
            return $next($request);
        }

        // Verify turnstile token
        if (!$this->turnstile->verifyRequest($request)) {
            return response()->json([
                'message' => 'فشل التحقق الأمني. يرجى المحاولة مرة أخرى.',
                'error' => 'Turnstile verification failed',
            ], 422);
        }

        return $next($request);
    }
}

