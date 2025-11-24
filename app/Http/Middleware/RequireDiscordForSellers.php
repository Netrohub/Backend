<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDiscordForSellers
{
    /**
     * Handle an incoming request.
     * 
     * Sellers must have Discord connected before:
     * - Creating listings
     * - Editing listings
     * - Marking delivery
     * - Seller actions in disputes
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
        
        // Check if user is a seller (has listings or is_seller flag)
        $isSeller = $user->is_seller || $user->listings()->exists();
        
        // For listing/dispute operations, require Discord
        if ($isSeller && !$user->hasDiscord()) {
            return response()->json([
                'error' => 'discord_required_for_sellers',
                'message' => 'You must connect your Discord account before listing or selling.',
            ], 403);
        }
        
        return $next($request);
    }
}

