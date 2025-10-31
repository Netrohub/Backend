<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Models\Listing;
use App\Models\KycVerification;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!$request->user()->isAdmin()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            return $next($request);
        });
    }

    public function users(Request $request)
    {
        $users = User::with(['wallet', 'kycVerification'])
            ->withCount(['listings', 'ordersAsBuyer', 'ordersAsSeller'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($users);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role' => 'sometimes|in:user,admin',
            'is_verified' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        return response()->json($user);
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    public function disputes(Request $request)
    {
        $disputes = Dispute::with(['order', 'initiator', 'resolver'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($disputes);
    }

    public function listings(Request $request)
    {
        $listings = Listing::with('user')
            ->withCount('orders')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($listings);
    }

    public function orders(Request $request)
    {
        $orders = Order::with(['listing', 'buyer', 'seller', 'dispute', 'payment'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    public function kyc(Request $request)
    {
        $kycs = KycVerification::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($kycs);
    }
}
