<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->headers->get('Origin');

        // Handle preflight OPTIONS requests
        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 200);
            
            // Set allowed origin
            if ($origin && in_array($origin, $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
            } elseif (!empty($allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $allowedOrigins[0]);
            } else {
                $response->headers->set('Access-Control-Allow-Origin', '*');
            }
            
            // Set allowed methods
            $allowedMethods = config('cors.allowed_methods', ['*']);
            $methodsString = is_array($allowedMethods) ? implode(', ', $allowedMethods) : '*';
            $response->headers->set('Access-Control-Allow-Methods', $methodsString);
            
            // Set allowed headers
            $allowedHeaders = config('cors.allowed_headers', ['*']);
            $headersString = is_array($allowedHeaders) ? implode(', ', $allowedHeaders) : '*';
            $response->headers->set('Access-Control-Allow-Headers', $headersString);
            
            $response->headers->set('Access-Control-Max-Age', (string) config('cors.max_age', 0));
            
            if (config('cors.supports_credentials', false)) {
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
            
            return $response;
        }

        // Handle actual requests
        $response = $next($request);

        // Set allowed origin
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } elseif (!empty($allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigins[0]);
        } else {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }

        // Set allowed methods
        $allowedMethods = config('cors.allowed_methods', ['*']);
        $methodsString = is_array($allowedMethods) ? implode(', ', $allowedMethods) : '*';
        $response->headers->set('Access-Control-Allow-Methods', $methodsString);
        
        // Set allowed headers
        $allowedHeaders = config('cors.allowed_headers', ['*']);
        $headersString = is_array($allowedHeaders) ? implode(', ', $allowedHeaders) : '*';
        $response->headers->set('Access-Control-Allow-Headers', $headersString);
        
        if (config('cors.supports_credentials', false)) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
