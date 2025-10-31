<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddRequestId
{
    /**
     * Handle an incoming request.
     * Generates a unique request ID and adds it to logs and response headers.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate or use existing request ID from header
        $requestId = $request->header('X-Request-ID', (string) Str::uuid());
        
        // Add request ID to request for use in controllers
        $request->merge(['request_id' => $requestId]);
        
        // Set context for all logs in this request
        Log::withContext(['request_id' => $requestId]);
        
        // Process request
        $response = $next($request);
        
        // Add request ID to response headers for client-side debugging
        $response->headers->set('X-Request-ID', $requestId);
        
        return $response;
    }
}
