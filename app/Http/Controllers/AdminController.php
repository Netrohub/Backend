<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Models\Listing;
use App\Models\KycVerification;
use Illuminate\Http\Request;
use App\Http\Controllers\MessageHelper;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!$request->user()->isAdmin()) {
                return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
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
        $validated = $request->validate([
            'role' => 'sometimes|in:user,admin',
            'is_verified' => 'sometimes|boolean',
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $id,
        ]);

        $user = User::findOrFail($id);
        $user->update($validated);

        return response()->json($user->load(['wallet', 'kycVerification']));
    }

    public function deleteUser(Request $request, $id)
    {
        $validated = $request->validate([
            'confirm' => 'sometimes|boolean',
        ]);

        $user = User::findOrFail($id);
        
        // Prevent admin from deleting themselves
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => MessageHelper::ADMIN_CANNOT_DELETE_SELF], 400);
        }

        $user->delete();

        return response()->json(['message' => MessageHelper::ADMIN_USER_DELETED]);
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
