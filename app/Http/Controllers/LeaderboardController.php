<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LeaderboardController extends Controller
{
    public function index(Request $request)
    {
        // Cache leaderboard for 15 minutes
        // Cache key includes verified status to ensure consistency
        $cacheKey = 'leaderboard_top_100';
        $cacheTTL = 60 * 15; // 15 minutes

        $users = Cache::remember($cacheKey, $cacheTTL, function () {
            return User::where('is_verified', true)
                ->whereHas('wallet')
                ->with('wallet')
                ->get()
                ->map(function($user) {
                    $totalRevenue = ($user->wallet->available_balance ?? 0) + 
                                   ($user->wallet->on_hold_balance ?? 0) + 
                                   ($user->wallet->withdrawn_total ?? 0);
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar' => $user->avatar,
                        'total_revenue' => $totalRevenue,
                        'total_sales' => $user->ordersAsSeller()->where('status', 'completed')->count(),
                    ];
                })
                ->sortByDesc('total_revenue')
                ->values()
                ->take(100)
                ->toArray();
        });

        return response()->json($users);
    }
}
