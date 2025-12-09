<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDiscordForDisputes
{
    /**
     * Handle an incoming request.
     * 
     * Buyers must have Discord connected before opening disputes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'error' => 'authentication_required',
                'message' => 'Authentication required.',
            ], 401);
        }
        
        if (!$user->hasDiscord()) {
            return response()->json([
                'error' => 'discord_required_for_disputes',
                'message' => 'Please connect your Discord account before opening a dispute.',
            ], 403);
        }
        
        return $next($request);
    }
}

