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

    /**
     * Get admin dashboard statistics
     */
    public function stats(Request $request)
    {
        try {
            // Cache stats for 5 minutes
            $stats = \Illuminate\Support\Facades\Cache::remember('admin_dashboard_stats', 300, function () {
                $now = now();
                $lastMonth = now()->subMonth();
                
                // Helper function for safe growth calculation
                $calculateGrowth = function ($current, $previous) {
                    if ($previous == 0) {
                        return $current > 0 ? 100 : 0;
                    }
                    return (($current - $previous) / $previous) * 100;
                };
                
                // Current month stats
                $currentMonthUsers = User::whereMonth('created_at', $now->month)
                    ->whereYear('created_at', $now->year)
                    ->count();
                $lastMonthUsers = User::whereMonth('created_at', $lastMonth->month)
                    ->whereYear('created_at', $lastMonth->year)
                    ->count();
                $usersGrowth = $calculateGrowth($currentMonthUsers, $lastMonthUsers);

                $currentMonthListings = Listing::where('status', 'active')
                    ->whereMonth('created_at', $now->month)
                    ->whereYear('created_at', $now->year)
                    ->count();
                $lastMonthListings = Listing::where('status', 'active')
                    ->whereMonth('created_at', $lastMonth->month)
                    ->whereYear('created_at', $lastMonth->year)
                    ->count();
                $listingsGrowth = $calculateGrowth($currentMonthListings, $lastMonthListings);

                $currentMonthOrders = Order::whereMonth('created_at', $now->month)
                    ->whereYear('created_at', $now->year)
                    ->count();
                $lastMonthOrders = Order::whereMonth('created_at', $lastMonth->month)
                    ->whereYear('created_at', $lastMonth->year)
                    ->count();
                $ordersGrowth = $calculateGrowth($currentMonthOrders, $lastMonthOrders);

                $currentMonthRevenue = Order::where('status', 'completed')
                    ->whereMonth('created_at', $now->month)
                    ->whereYear('created_at', $now->year)
                    ->sum('amount') ?? 0;
                $lastMonthRevenue = Order::where('status', 'completed')
                    ->whereMonth('created_at', $lastMonth->month)
                    ->whereYear('created_at', $lastMonth->year)
                    ->sum('amount') ?? 0;
                $revenueGrowth = $calculateGrowth($currentMonthRevenue, $lastMonthRevenue);

                return [
                    'total_users' => User::count(),
                    'users_growth' => round($usersGrowth, 1),
                    'active_listings' => Listing::where('status', 'active')->count(),
                    'listings_growth' => round($listingsGrowth, 1),
                    'orders_this_month' => $currentMonthOrders,
                    'orders_growth' => round($ordersGrowth, 1),
                    'total_revenue' => (float) $currentMonthRevenue,
                    'revenue_growth' => round($revenueGrowth, 1),
                    'open_disputes' => Dispute::where('status', 'open')->count(),
                    'pending_kyc' => KycVerification::where('status', 'pending')->count(),
                ];
            });

            return response()->json($stats);
        } catch (\Exception $e) {
            \Log::error('Admin stats error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return safe defaults on error
            return response()->json([
                'total_users' => User::count(),
                'users_growth' => 0,
                'active_listings' => Listing::where('status', 'active')->count(),
                'listings_growth' => 0,
                'orders_this_month' => 0,
                'orders_growth' => 0,
                'total_revenue' => 0,
                'revenue_growth' => 0,
                'open_disputes' => Dispute::where('status', 'open')->count(),
                'pending_kyc' => KycVerification::where('status', 'pending')->count(),
            ]);
        }
    }

    /**
     * Get recent admin activity/audit logs
     */
    public function activity(Request $request)
    {
        // Get logs (cached for 1 minute)
        $logs = \Illuminate\Support\Facades\Cache::remember('admin_recent_activity_logs', 60, function () {
            return \App\Models\AuditLog::with('user')
                ->whereIn('action', [
                    'user.registered',
                    'order.created',
                    'dispute.resolved',
                    'listing.created',
                    'kyc.verified',
                ])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        });

        // Map to response format (outside cache to access $this)
        $activities = $logs->map(function ($log) {
            return [
                'action' => $this->formatAction($log->action),
                'user' => $log->user->name ?? 'مستخدم محذوف',
                'timestamp' => $log->created_at->toIso8601String(),
                'color' => $this->getActionColor($log->action),
            ];
        });

        return response()->json($activities);
    }

    /**
     * Update listing status (admin moderation)
     */
    public function updateListingStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,sold',
        ]);

        $listing = Listing::findOrFail($id);
        $oldStatus = $listing->status;
        $listing->status = $validated['status'];
        $listing->save();

        // Audit log
        AuditHelper::log(
            'admin.listing.status_update',
            Listing::class,
            $listing->id,
            ['status' => $oldStatus],
            ['status' => $listing->status],
            $request
        );

        return response()->json([
            'message' => 'Listing status updated',
            'listing' => $listing->load('user'),
        ]);
    }

    /**
     * Delete listing (admin)
     */
    public function deleteListing(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);
        $listingData = $listing->only(['id', 'title', 'user_id']);
        
        $listing->delete();

        // Audit log
        AuditHelper::log(
            'admin.listing.delete',
            Listing::class,
            $listingData['id'],
            $listingData,
            null,
            $request
        );

        return response()->json(['message' => 'Listing deleted successfully']);
    }

    /**
     * Cancel order (admin)
     */
    public function cancelOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $order = Order::with(['buyer', 'seller'])->findOrFail($id);
        
        if ($order->status === 'completed' || $order->status === 'cancelled') {
            return response()->json([
                'message' => 'Cannot cancel completed or already cancelled orders',
            ], 400);
        }

        $oldStatus = $order->status;
        $order->status = 'cancelled';
        $order->cancellation_reason = $validated['reason'];
        $order->cancelled_by = 'admin';
        $order->cancelled_at = now();
        $order->save();

        // Refund logic if payment was made
        if ($order->payment && $order->payment->status === 'paid') {
            // Return to buyer's wallet
            $buyerWallet = $order->buyer->wallet;
            if ($buyerWallet) {
                $buyerWallet->available_balance += $order->amount;
                $buyerWallet->save();
            }
        }

        // Audit log
        AuditHelper::log(
            'admin.order.cancel',
            Order::class,
            $order->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled', 'reason' => $validated['reason']],
            $request
        );

        // Notify both parties
        $order->buyer->notify(new \App\Notifications\OrderStatusChanged($order));
        $order->seller->notify(new \App\Notifications\OrderStatusChanged($order));

        return response()->json([
            'message' => 'Order cancelled successfully',
            'order' => $order->load(['buyer', 'seller', 'payment']),
        ]);
    }

    private function formatAction($action)
    {
        $map = [
            'user.registered' => 'مستخدم جديد انضم',
            'order.created' => 'طلب جديد',
            'dispute.resolved' => 'نزاع تم حله',
            'listing.created' => 'إعلان جديد',
            'kyc.verified' => 'توثيق KYC',
        ];
        return $map[$action] ?? $action;
    }

    private function getActionColor($action)
    {
        $map = [
            'user.registered' => 'text-[hsl(195,80%,70%)]',
            'order.created' => 'text-green-400',
            'dispute.resolved' => 'text-[hsl(280,70%,70%)]',
            'listing.created' => 'text-[hsl(40,90%,70%)]',
            'kyc.verified' => 'text-blue-400',
        ];
        return $map[$action] ?? 'text-white/60';
    }
}
