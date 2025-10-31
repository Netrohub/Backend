<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Controllers\MessageHelper;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     * Ensures the authenticated user has admin role.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json([
                'message' => MessageHelper::ERROR_UNAUTHORIZED
            ], 403);
        }

        return $next($request);
    }
}
