<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $request)
    {
        $users = User::where('is_verified', true)
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
            ->take(100);

        return response()->json($users);
    }
}
