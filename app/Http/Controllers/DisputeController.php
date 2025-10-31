<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\MessageHelper;

class DisputeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $disputes = Dispute::with(['order', 'initiator', 'resolver'])
            ->whereHas('order', function($query) use ($user) {
                $query->where('buyer_id', $user->id)
                      ->orWhere('seller_id', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($disputes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'reason' => 'required|string|max:255',
            'description' => 'required|string',
        ]);

        $order = Order::findOrFail($validated['order_id']);
        $user = $request->user();

        // Only buyer or seller can create dispute
        if ($order->buyer_id !== $user->id && $order->seller_id !== $user->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        // Check if dispute already exists
        if ($order->dispute) {
            return response()->json(['message' => MessageHelper::DISPUTE_ALREADY_EXISTS], 400);
        }

        // Order must be in escrow_hold status
        if ($order->status !== 'escrow_hold') {
            return response()->json(['message' => MessageHelper::DISPUTE_ONLY_ESCROW], 400);
        }

        $dispute = Dispute::create([
            'order_id' => $order->id,
            'initiated_by' => $user->id,
            'party' => $order->buyer_id === $user->id ? 'buyer' : 'seller',
            'reason' => $validated['reason'],
            'description' => $validated['description'],
            'status' => 'open',
        ]);

        // Update order status
        $order->status = 'disputed';
        $order->save();

        return response()->json($dispute->load(['order', 'initiator']), 201);
    }

    public function show(Request $request, $id)
    {
        $dispute = Dispute::with(['order', 'initiator', 'resolver'])
            ->findOrFail($id);

        $user = $request->user();
        $order = $dispute->order;

        // Only buyer, seller, or admin can view dispute
        if ($order->buyer_id !== $user->id && $order->seller_id !== $user->id && !$user->isAdmin()) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        return response()->json($dispute);
    }

    public function update(Request $request, $id)
    {
        $dispute = Dispute::findOrFail($id);
        $user = $request->user();

        // Only admin can update dispute status
        if (!$user->isAdmin()) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:under_review,resolved,closed',
            'resolution_notes' => 'required_if:status,resolved|string',
            'resolution' => 'required_if:status,resolved|in:refund_buyer,release_to_seller',
        ]);

        $dispute->status = $validated['status'];
        $dispute->resolved_by = $user->id;
        $dispute->resolved_at = now();
        
        if (isset($validated['resolution_notes'])) {
            $dispute->resolution_notes = $validated['resolution_notes'];
        }

        $dispute->save();

        // If resolved, release or refund funds based on resolution
        if ($validated['status'] === 'resolved' && isset($validated['resolution'])) {
            $order = $dispute->order;
            
            // Only process if order is in escrow
            if ($order->status === 'disputed' && $order->escrow_hold_at) {
                DB::transaction(function () use ($order, $validated) {
                    if ($validated['resolution'] === 'refund_buyer') {
                        // Refund buyer - move funds from escrow back to buyer's available balance
                        $buyerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->buyer_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );
                        
                        // Validate escrow balance
                        if ($buyerWallet->on_hold_balance < $order->amount) {
                            throw new \Exception(MessageHelper::ORDER_INSUFFICIENT_ESCROW);
                        }
                        
                        $buyerWallet->available_balance += $order->amount;
                        $buyerWallet->on_hold_balance -= $order->amount;
                        $buyerWallet->save();
                        
                        $order->status = 'cancelled';
                    } elseif ($validated['resolution'] === 'release_to_seller') {
                        // Release to seller - move funds from escrow to seller's available balance
                        $sellerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->seller_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );
                        
                        // For seller, we need to check buyer's escrow balance
                        $buyerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->buyer_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );
                        
                        if ($buyerWallet->on_hold_balance < $order->amount) {
                            throw new \Exception(MessageHelper::ORDER_INSUFFICIENT_ESCROW);
                        }
                        
                        // Move from buyer's escrow to seller's available balance
                        $buyerWallet->on_hold_balance -= $order->amount;
                        $buyerWallet->save();
                        
                        $sellerWallet->available_balance += $order->amount;
                        $sellerWallet->save();
                        
                        $order->status = 'completed';
                        $order->completed_at = now();
                    }
                    
                    $order->save();
                });
            }
        }

        return response()->json($dispute->load(['order', 'initiator', 'resolver']));
    }
}
