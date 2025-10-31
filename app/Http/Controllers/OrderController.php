<?php

namespace App\Http\Controllers;

use App\Jobs\ReleaseEscrowFunds;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\MessageHelper;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $orders = Order::with(['listing', 'buyer', 'seller', 'dispute', 'payment'])
            ->where(function($query) use ($user) {
                $query->where('buyer_id', $user->id)
                      ->orWhere('seller_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'listing_id' => 'required|exists:listings,id',
            'notes' => 'nullable|string',
        ]);

        $listing = Listing::findOrFail($validated['listing_id']);

        if ($listing->user_id === $request->user()->id) {
            return response()->json(['message' => MessageHelper::ORDER_CANNOT_BUY_OWN], 400);
        }

        if ($listing->status !== 'active') {
            return response()->json(['message' => MessageHelper::ORDER_NOT_AVAILABLE], 400);
        }

        // Wrap order creation in transaction for data consistency
        $order = DB::transaction(function () use ($validated, $listing, $request) {
            return Order::create([
                'listing_id' => $listing->id,
                'buyer_id' => $request->user()->id,
                'seller_id' => $listing->user_id,
                'amount' => $listing->price,
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json($order->load(['listing', 'buyer', 'seller']), 201);
    }

    public function show(Request $request, $id)
    {
        $order = Order::with(['listing', 'buyer', 'seller', 'dispute', 'payment'])
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

            $order->status = 'cancelled';
        }

        if (isset($validated['notes'])) {
            $order->notes = $validated['notes'];
        }

        $order->save();

        return response()->json($order->load(['listing', 'buyer', 'seller']));
    }
}
