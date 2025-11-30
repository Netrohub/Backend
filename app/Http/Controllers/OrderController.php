<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Wallet;
use App\Notifications\OrderStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\MessageHelper;
use App\Helpers\PaginationHelper;
use App\Helpers\AuditHelper;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // CRITICAL: Only show REAL orders (exclude payment_intent - those are not orders yet)
        // payment_intent = temporary, not a real order until payment is confirmed
        $orders = PaginationHelper::paginate(
            Order::with(['listing', 'buyer', 'seller', 'dispute', 'payment'])
                ->withActiveUsers() // Only show orders from active (non-deleted) users
                ->where('status', '!=', 'payment_intent') // Exclude payment intents - they're not real orders
                ->where(function($query) use ($user) {
                    $query->where('buyer_id', $user->id)
                          ->orWhere('seller_id', $user->id);
                })
                ->orderBy('created_at', 'desc'),
            $request
        );

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'listing_id' => 'required|exists:listings,id',
            'notes' => 'nullable|string',
        ]);

        try {
            // Wrap order creation in transaction with pessimistic locking to prevent race conditions
            $order = DB::transaction(function () use ($validated, $request) {
                // SECURITY: Lock listing row to prevent concurrent orders for same listing
                $listing = Listing::lockForUpdate()->findOrFail($validated['listing_id']);

                // Re-check authorization after lock
                if ($listing->user_id === $request->user()->id) {
                    throw new \Exception(MessageHelper::ORDER_CANNOT_BUY_OWN);
                }

                // Require Discord connection for buyer
                $buyer = $request->user();
                // Refresh buyer to ensure we have latest Discord status
                $buyer->refresh();
                if (!$buyer->discord_user_id) {
                    throw new \Exception(MessageHelper::ORDER_BUYER_DISCORD_REQUIRED);
                }

                // Require Discord connection for seller
                // Fetch seller fresh to ensure we have latest Discord status (not from relationship cache)
                $seller = User::find($listing->user_id);
                if (!$seller) {
                    throw new \Exception('Seller not found.');
                }
                
                // Debug logging for Discord connection check
                Log::info('Checking seller Discord connection', [
                    'seller_id' => $seller->id,
                    'discord_user_id' => $seller->discord_user_id,
                    'discord_username' => $seller->discord_username,
                    'discord_connected_at' => $seller->discord_connected_at,
                ]);
                
                if (!$seller->discord_user_id) {
                    throw new \Exception(MessageHelper::ORDER_SELLER_DISCORD_REQUIRED);
                }

                // Re-check status after lock (prevents race condition)
                if ($listing->status !== 'active') {
                    throw new \Exception(MessageHelper::ORDER_NOT_AVAILABLE);
                }

                // SECURITY: Check for existing REAL orders to prevent double-selling
                // payment_intent is NOT a real order - only escrow_hold, disputed, and completed are real orders
                // Multiple payment_intent orders are allowed (users can create payment intents before paying)
                $existingRealOrder = Order::where('listing_id', $listing->id)
                    ->whereIn('status', ['escrow_hold', 'disputed', 'completed']) // Only REAL orders (payment confirmed)
                    ->first();

                if ($existingRealOrder) {
                    throw new \Exception(MessageHelper::ORDER_ALREADY_EXISTS);
                }

                // Create payment intent (not a real order yet - only becomes order after payment confirmation)
                return Order::create([
                    'listing_id' => $listing->id,
                    'buyer_id' => $request->user()->id,
                    'seller_id' => $listing->user_id,
                    'amount' => $listing->price, // Always use current listing price
                    'status' => 'payment_intent', // Not a real order until payment is confirmed
                    'notes' => $validated['notes'] ?? null,
                ]);
            });

            return response()->json($order->load(['listing', 'buyer', 'seller']), 201);
        } catch (\Exception $e) {
            $errorCode = 'ORDER_CREATE_FAILED';
            $userMessage = $e->getMessage();
            $httpStatus = 400; // Default to 400 for validation errors
            
            // Determine error code and HTTP status based on error message
            if (str_contains($e->getMessage(), 'SQLSTATE') || str_contains($e->getMessage(), 'database')) {
                $errorCode = 'ORDER_DATABASE_ERROR';
                $userMessage = 'Unable to create order due to a database error. Please try again later.';
                $httpStatus = 500;
            } elseif (str_contains($e->getMessage(), MessageHelper::ORDER_BUYER_DISCORD_REQUIRED)) {
                $errorCode = 'ORDER_BUYER_DISCORD_REQUIRED';
                $httpStatus = 400;
            } elseif (str_contains($e->getMessage(), MessageHelper::ORDER_SELLER_DISCORD_REQUIRED)) {
                $errorCode = 'ORDER_SELLER_DISCORD_REQUIRED';
                $httpStatus = 400;
            } elseif (str_contains($e->getMessage(), MessageHelper::ORDER_CANNOT_BUY_OWN)) {
                $errorCode = 'ORDER_CANNOT_BUY_OWN';
                $httpStatus = 400;
            } elseif (str_contains($e->getMessage(), MessageHelper::ORDER_NOT_AVAILABLE)) {
                $errorCode = 'ORDER_NOT_AVAILABLE';
                $httpStatus = 400;
            } elseif (str_contains($e->getMessage(), MessageHelper::ORDER_ALREADY_EXISTS)) {
                $errorCode = 'ORDER_ALREADY_EXISTS';
                $httpStatus = 400;
            }

            \Illuminate\Support\Facades\Log::error('Order creation failed', [
                'listing_id' => $validated['listing_id'] ?? null,
                'buyer_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'error_code' => $errorCode,
                'http_status' => $httpStatus,
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'message' => $userMessage,
                'error_code' => $errorCode,
                'error' => \App\Helpers\SecurityHelper::getSafeErrorMessage($e),
            ], $httpStatus);
        }
    }

    public function show(Request $request, $id)
    {
        // Use withTrashed to find orders even if soft-deleted (for viewing payment status)
        $order = Order::withTrashed()
            ->with(['listing', 'buyer', 'seller', 'dispute', 'payment'])
            ->findOrFail($id);

        $user = $request->user();

        // Only buyer, seller, or admin can view order
        if ($order->buyer_id !== $user->id && $order->seller_id !== $user->id && !$user->isAdmin()) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        return response()->json($order);
    }

    public function update(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        $user = $request->user();

        // Only buyer or seller can update
        if ($order->buyer_id !== $user->id && $order->seller_id !== $user->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:cancelled',
            'notes' => 'sometimes|string',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'cancelled') {
            // CRITICAL: Digital products (accounts) CANNOT be cancelled once payment is confirmed
            // Once credentials are shared (escrow_hold), cancellation is impossible
            // This prevents buyers from getting free accounts by cancelling after receiving credentials
            if ($order->status === 'escrow_hold') {
                return response()->json([
                    'message' => 'لا يمكن إلغاء الطلب - المنتج الرقمي (الحساب) لا يمكن إرجاعه بعد تأكيد الدفع ومشاركة البيانات',
                    'error_code' => 'CANNOT_CANCEL_DIGITAL_PRODUCT',
                    'reason' => 'Digital products cannot be cancelled once payment is confirmed and credentials are shared',
                ], 400);
            }
            
            // Handle payment_intent cancellation (not a real order yet, just delete it)
            if ($order->status === 'payment_intent') {
                // Payment intent was never paid, so just delete it (not a real order)
                $order->delete();
                return response()->json(['message' => 'تم إلغاء طلب الدفع'], 200);
            }
            
            // Cannot cancel completed orders
            if ($order->status === 'completed') {
                return response()->json([
                    'message' => 'لا يمكن إلغاء طلب مكتمل',
                    'error_code' => 'CANNOT_CANCEL_COMPLETED',
                ], 400);
            }

            $oldStatus = $order->status;
            $order->status = 'cancelled';
            $order->save();
            
            // Revert listing back to active if it was marked as sold (only for real orders)
            $listing = $order->listing;
            if ($listing && $listing->status === 'sold') {
                $listing->status = 'active';
                $listing->save();
            }
            
            // Audit log for order cancellation
            AuditHelper::log(
                'order.cancelled',
                Order::class,
                $order->id,
                ['status' => $oldStatus],
                ['status' => 'cancelled'],
                $request
            );
            
            // Send notifications to buyer and seller
            $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'cancelled'));
            $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'cancelled'));
        }

        if (isset($validated['notes'])) {
            $order->notes = $validated['notes'];
        }

        $order->save();

        return response()->json($order->load(['listing', 'buyer', 'seller']));
    }

    /**
     * Confirm order receipt (buyer only)
     * Changes status from escrow_hold to completed and releases funds to seller
     */
    public function confirm(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        $user = $request->user();

        // Only buyer can confirm
        if ($order->buyer_id !== $user->id) {
            return response()->json([
                'message' => 'فقط المشتري يمكنه تأكيد الطلب',
                'error_code' => 'ONLY_BUYER_CAN_CONFIRM',
            ], 403);
        }

        // Must be in escrow_hold status
        if ($order->status !== 'escrow_hold') {
            return response()->json([
                'message' => 'لا يمكن تأكيد هذا الطلب في حالته الحالية',
                'error_code' => 'INVALID_ORDER_STATUS',
            ], 400);
        }

        // Release funds to seller - MUST release from buyer's escrow first
        DB::transaction(function () use ($order, $user) {
            // Get buyer wallet with lock to release escrow
            $buyerWallet = Wallet::lockForUpdate()
                ->firstOrCreate(
                    ['user_id' => $order->buyer_id],
                    ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                );

            // Validate buyer has enough escrow balance
            if ($buyerWallet->on_hold_balance < $order->amount) {
                Log::error('Insufficient escrow balance for order confirmation', [
                    'order_id' => $order->id,
                    'buyer_id' => $order->buyer_id,
                    'required' => $order->amount,
                    'available' => $buyerWallet->on_hold_balance,
                ]);
                throw new \Exception('Insufficient escrow balance. Order requires manual review.');
            }

            // Release from buyer's escrow FIRST
            $buyerWallet->on_hold_balance -= $order->amount;
            $buyerWallet->save();

            // Then add to seller's available balance
            $sellerWallet = Wallet::lockForUpdate()
                ->firstOrCreate(
                    ['user_id' => $order->seller_id],
                    ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                );

            $sellerWallet->available_balance += $order->amount;
            $sellerWallet->save();

            // Update order status
            $oldStatus = $order->status;
            $order->status = 'completed';
            $order->completed_at = now();
            $order->save();

            // Audit log
            AuditHelper::log(
                'order.confirmed',
                Order::class,
                $order->id,
                ['status' => $oldStatus],
                ['status' => 'completed', 'confirmed_by' => $user->id],
                request()
            );

            // Notify seller
            $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'completed'));
        });

        return response()->json([
            'message' => 'تم تأكيد الطلب بنجاح',
            'order' => $order->fresh(['listing', 'buyer', 'seller']),
        ]);
    }

    /**
     * Cancel order (buyer or seller, with refund if in escrow)
     */
    public function cancel(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        $user = $request->user();

        // Only buyer or seller can cancel
        if ($order->buyer_id !== $user->id && $order->seller_id !== $user->id) {
            return response()->json([
                'message' => MessageHelper::ERROR_UNAUTHORIZED,
                'error_code' => 'UNAUTHORIZED',
            ], 403);
        }

        // Cannot cancel if already completed
        if ($order->status === 'completed') {
            return response()->json([
                'message' => 'لا يمكن إلغاء طلب مكتمل',
                'error_code' => 'CANNOT_CANCEL_COMPLETED',
            ], 400);
        }

        // CRITICAL: Digital products (accounts) CANNOT be cancelled once payment is confirmed
        // Once credentials are shared (escrow_hold), cancellation is impossible
        // This prevents buyers from getting free accounts by cancelling after receiving credentials
        if ($order->status === 'escrow_hold') {
            // Load listing to check if it's a digital product (account listing)
            $listing = $order->listing;
            
            // All listings in this platform are digital products (accounts)
            // Digital products cannot be returned or cancelled once payment is confirmed
            // because credentials have already been shared with the buyer
            return response()->json([
                'message' => 'لا يمكن إلغاء الطلب - المنتج الرقمي (الحساب) لا يمكن إرجاعه بعد تأكيد الدفع ومشاركة البيانات',
                'error_code' => 'CANNOT_CANCEL_DIGITAL_PRODUCT',
                'reason' => 'Digital products cannot be cancelled once payment is confirmed and credentials are shared',
            ], 400);
        }

        // Handle payment_intent cancellation (not a real order yet, just delete it)
        if ($order->status === 'payment_intent') {
            // Payment intent was never paid, so just delete it (not a real order)
            $order->delete();
            return response()->json([
                'message' => 'تم إلغاء طلب الدفع',
                'order' => null,
            ], 200);
        }

        // For any other status (escrow_hold already blocked above, this should not reach here)
        // This prevents cancellation for orders that are not payment_intent
        return response()->json([
            'message' => 'لا يمكن إلغاء الطلب - المنتج الرقمي (الحساب) لا يمكن إرجاعه بعد تأكيد الدفع',
            'error_code' => 'CANNOT_CANCEL_AFTER_PAYMENT',
        ], 400);
        
        // Revert listing back to active if it was marked as sold
        $listing = $order->listing;
        if ($listing && $listing->status === 'sold') {
            $listing->status = 'active';
            $listing->save();
        }
        
        // Audit log
        AuditHelper::log(
            'order.cancelled',
            Order::class,
            $order->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled', 'cancelled_by' => $user->id],
            $request
        );
        
        // Notify both parties
        $order->buyer->notify(new OrderStatusChanged($order, $oldStatus, 'cancelled'));
        $order->seller->notify(new OrderStatusChanged($order, $oldStatus, 'cancelled'));

        return response()->json([
            'message' => 'تم إلغاء الطلب بنجاح',
            'order' => $order->fresh(['listing', 'buyer', 'seller']),
        ]);
    }
}
