<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Wallet;
use App\Notifications\OrderStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    throw new \Exception('This listing already has an active order. Please try another listing.');
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
            $userMessage = 'Failed to create order. Please try again.';
            
            if (str_contains($e->getMessage(), 'SQLSTATE') || str_contains($e->getMessage(), 'database')) {
                $errorCode = 'ORDER_DATABASE_ERROR';
                $userMessage = 'Unable to create order due to a database error. Please try again later.';
            }

            \Illuminate\Support\Facades\Log::error('Order creation failed', [
                'listing_id' => $listing->id,
                'buyer_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'error_code' => $errorCode,
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'message' => $userMessage,
                'error_code' => $errorCode,
                'error' => \App\Helpers\SecurityHelper::getSafeErrorMessage($e),
            ], 500);
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
            // Handle payment_intent cancellation (not a real order yet, just delete it)
            if ($order->status === 'payment_intent') {
                // Payment intent was never paid, so just delete it (not a real order)
                $order->delete();
                return response()->json(['message' => 'تم إلغاء طلب الدفع'], 200);
            }
            
            if ($order->status === 'escrow_hold') {
                // Refund buyer - wrapped in transaction to prevent race conditions
                DB::transaction(function () use ($order) {
                    $buyerWallet = Wallet::lockForUpdate()
                        ->firstOrCreate(
                            ['user_id' => $order->buyer_id],
                            ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                        );
                    
                    // Validate balance before refund
                    if ($buyerWallet->on_hold_balance < $order->amount) {
                        throw new \Exception(MessageHelper::ORDER_INSUFFICIENT_ESCROW);
                    }
                    
                    $buyerWallet->available_balance += $order->amount;
                    $buyerWallet->on_hold_balance -= $order->amount;
                    $buyerWallet->save();
                });
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

        // Release funds to seller
        DB::transaction(function () use ($order, $user) {
            $sellerWallet = Wallet::lockForUpdate()
                ->firstOrCreate(
                    ['user_id' => $order->seller_id],
                    ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                );

            // Transfer from escrow (on_hold) to seller's available balance
            $sellerWallet->available_balance += $order->amount;
            $sellerWallet->save();

            // Update order status
            $oldStatus = $order->status;
            $order->status = 'completed';
            $order->confirmed_at = now();
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

        // Handle payment_intent cancellation (not a real order yet, just delete it)
        if ($order->status === 'payment_intent') {
            // Payment intent was never paid, so just delete it (not a real order)
            $order->delete();
            return response()->json([
                'message' => 'تم إلغاء طلب الدفع',
                'order' => null,
            ], 200);
        }

        // Handle refund if in escrow (real order)
        if ($order->status === 'escrow_hold') {
            DB::transaction(function () use ($order) {
                $buyerWallet = Wallet::lockForUpdate()
                    ->firstOrCreate(
                        ['user_id' => $order->buyer_id],
                        ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                    );
                
                // Validate balance before refund
                if ($buyerWallet->on_hold_balance < $order->amount) {
                    throw new \Exception(MessageHelper::ORDER_INSUFFICIENT_ESCROW);
                }
                
                $buyerWallet->available_balance += $order->amount;
                $buyerWallet->on_hold_balance -= $order->amount;
                $buyerWallet->save();
            });
        }

        $oldStatus = $order->status;
        $order->status = 'cancelled';
        $order->save();
        
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
