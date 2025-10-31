<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Models\Listing;
use App\Models\KycVerification;
use Illuminate\Http\Request;
use App\Http\Controllers\MessageHelper;
use App\Helpers\PaginationHelper;
use App\Helpers\AuditHelper;

class AdminController extends Controller
{
    // Admin middleware is now applied at route level (see routes/api.php)
    // This ensures better security and clearer separation of concerns

    public function users(Request $request)
    {
        $users = PaginationHelper::paginate(
            User::with(['wallet', 'kycVerification'])
                ->withCount(['listings', 'ordersAsBuyer', 'ordersAsSeller'])
                ->orderBy('created_at', 'desc'),
            $request
        );

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
        $oldValues = $user->only(['role', 'is_verified', 'name', 'email']);
        $user->update($validated);

        // Audit log for admin user update
        AuditHelper::log(
            'admin.user.update',
            User::class,
            $user->id,
            $oldValues,
            $user->only(['role', 'is_verified', 'name', 'email']),
            $request
        );

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

        $userData = $user->only(['id', 'name', 'email', 'role']);
        $user->delete();

        // Audit log for admin user deletion
        AuditHelper::log(
            'admin.user.delete',
            User::class,
            $userData['id'],
            $userData,
            null,
            $request
        );

        return response()->json(['message' => MessageHelper::ADMIN_USER_DELETED]);
    }

    public function disputes(Request $request)
    {
        $disputes = PaginationHelper::paginate(
            Dispute::with(['order', 'initiator', 'resolver'])
                ->orderBy('created_at', 'desc'),
            $request
        );

        return response()->json($disputes);
    }

    public function listings(Request $request)
    {
        $listings = PaginationHelper::paginate(
            Listing::with('user')
                ->withCount('orders')
                ->orderBy('created_at', 'desc'),
            $request
        );

        return response()->json($listings);
    }

    public function orders(Request $request)
    {
        $orders = PaginationHelper::paginate(
            Order::with(['listing', 'buyer', 'seller', 'dispute', 'payment'])
                ->orderBy('created_at', 'desc'),
            $request
        );

        return response()->json($orders);
    }

    public function kyc(Request $request)
    {
        $kycs = PaginationHelper::paginate(
            KycVerification::with('user')
                ->orderBy('created_at', 'desc'),
            $request
        );

        return response()->json($kycs);
    }
}
