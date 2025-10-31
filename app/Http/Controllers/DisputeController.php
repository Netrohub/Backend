<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Order;
use Illuminate\Http\Request;

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
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if dispute already exists
        if ($order->dispute) {
            return response()->json(['message' => 'Dispute already exists for this order'], 400);
        }

        // Order must be in escrow_hold status
        if ($order->status !== 'escrow_hold') {
            return response()->json(['message' => 'Dispute can only be created for orders in escrow'], 400);
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

    public function show($id)
    {
        $dispute = Dispute::with(['order', 'initiator', 'resolver'])
            ->findOrFail($id);

        return response()->json($dispute);
    }

    public function update(Request $request, $id)
    {
        $dispute = Dispute::findOrFail($id);
        $user = $request->user();

        // Only admin can update dispute status
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:under_review,resolved,closed',
            'resolution_notes' => 'required_if:status,resolved|string',
        ]);

        $dispute->status = $validated['status'];
        $dispute->resolved_by = $user->id;
        $dispute->resolved_at = now();
        
        if (isset($validated['resolution_notes'])) {
            $dispute->resolution_notes = $validated['resolution_notes'];
        }

        $dispute->save();

        // If resolved, release or refund funds based on resolution
        if ($validated['status'] === 'resolved') {
            // This would need additional logic based on resolution
            // For now, we'll just mark the order
            $dispute->order->status = 'disputed';
            $dispute->order->save();
        }

        return response()->json($dispute->load(['order', 'initiator', 'resolver']));
    }
}
