<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Wallet;
use App\Notifications\DisputeCreated;
use App\Notifications\DisputeResolved;
use App\Services\DisputeEventEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\MessageHelper;
use App\Helpers\PaginationHelper;
use App\Helpers\AuditHelper;

class DisputeController extends Controller
{
    // Dispute creation deadline (14 days from order creation)
    const DISPUTE_DEADLINE_DAYS = 14;

    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Dispute::with(['order', 'initiator', 'resolver'])
            ->whereHas('order', function($q) use ($user) {
                $q->withActiveUsers() // Only show disputes for orders with active users
                  ->where('buyer_id', $user->id)
                  ->orWhere('seller_id', $user->id);
            });

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $query->orderBy('created_at', 'desc');
        
        $disputes = PaginationHelper::paginate($query, $request);

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
        $buyer = $order->buyer;
        $seller = $order->seller;

        // Only buyer or seller can create dispute
        if ($order->buyer_id !== $user->id && $order->seller_id !== $user->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        // Require Discord connection only for the person creating the dispute (initiator)
        // The other party can connect Discord later, but the initiator must have Discord
        // to create the Discord thread for dispute management
        $isBuyer = $order->buyer_id === $user->id;
        $initiator = $isBuyer ? $buyer : $seller;
        
        if (!$initiator->discord_user_id) {
            $partyName = $isBuyer ? 'Buyer' : 'Seller';
            return response()->json([
                'message' => "You must connect your Discord account to create disputes. Please connect Discord first.",
                'error' => 'discord_required_for_initiator',
                'party' => $isBuyer ? 'buyer' : 'seller',
            ], 400);
        }

        // Log if the other party doesn't have Discord (for tracking, but don't block)
        $otherParty = $isBuyer ? $seller : $buyer;
        if (!$otherParty->discord_user_id) {
            Log::info('Dispute created but other party missing Discord', [
                'dispute_initiator' => $user->id,
                'other_party_id' => $otherParty->id,
                'other_party_type' => $isBuyer ? 'seller' : 'buyer',
                'order_id' => $order->id,
            ]);
            // Note: We'll still create the dispute, but the other party will need to connect Discord
            // to participate in the Discord thread. They'll be notified via email/in-app notification.
        }

        // Check if dispute already exists
        if ($order->dispute) {
            return response()->json(['message' => MessageHelper::DISPUTE_ALREADY_EXISTS], 400);
        }

        // Order must be in escrow_hold status
        if ($order->status !== 'escrow_hold') {
            return response()->json(['message' => MessageHelper::DISPUTE_ONLY_ESCROW], 400);
        }

        // Check if order is within dispute deadline (14 days)
        $orderAge = now()->diffInDays($order->created_at);
        if ($orderAge > self::DISPUTE_DEADLINE_DAYS) {
            return response()->json([
                'message' => "لا يمكن فتح نزاع على طلب مضى عليه أكثر من " . self::DISPUTE_DEADLINE_DAYS . " يوماً",
                'error_code' => 'DISPUTE_DEADLINE_PASSED',
                'deadline_days' => self::DISPUTE_DEADLINE_DAYS,
            ], 400);
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
        $oldOrderStatus = $order->status;
        $order->status = 'disputed';
        $order->save();

        // Audit log for dispute creation
        AuditHelper::log(
            'dispute.created',
            Dispute::class,
            $dispute->id,
            [],
            [
                'order_id' => $order->id,
                'initiated_by' => $user->id,
                'party' => $dispute->party,
                'reason' => $dispute->reason,
                'order_status_before' => $oldOrderStatus,
                'order_status_after' => 'disputed',
            ],
            $request
        );

        // Send notifications to buyer and seller
        // Pass information about Discord connection status for better messaging
        $order->buyer->notify(new DisputeCreated($dispute, !$buyer->discord_user_id));
        $order->seller->notify(new DisputeCreated($dispute, !$seller->discord_user_id));

        // Emit Discord event (non-blocking)
        try {
            DisputeEventEmitter::created($dispute);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Discord dispute event failed', [
                'dispute_id' => $dispute->id,
                'error' => $e->getMessage(),
            ]);
        }

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
            'resolution' => 'nullable|string|in:buyer,seller,refund_buyer,release_to_seller',
            'resolution_notes' => 'nullable|string',
            'admin_notes' => 'nullable|string',
        ]);

        $oldStatus = $dispute->status;
        $oldResolution = $dispute->resolution;
        $dispute->status = $validated['status'];
        $dispute->resolved_by = $user->id;
        $dispute->resolved_at = now();

        $submittedResolution = $validated['resolution'] ?? null;
        $normalizedResolution = $this->normalizeResolution($submittedResolution);
        $resolutionNotes = $validated['resolution_notes'] ?? $validated['admin_notes'] ?? null;

        if ($validated['status'] === 'resolved') {
            if (!$normalizedResolution) {
                throw ValidationException::withMessages([
                    'resolution' => ['Resolution selection is required when resolving a dispute.'],
                ]);
            }

            if (empty($resolutionNotes)) {
                throw ValidationException::withMessages([
                    'resolution_notes' => ['Resolution notes are required when resolving a dispute.'],
                ]);
            }

            $dispute->resolution = $normalizedResolution;
            $dispute->resolution_notes = $resolutionNotes;
        } else {
            $dispute->resolution = $normalizedResolution;
            if ($resolutionNotes !== null) {
                $dispute->resolution_notes = $resolutionNotes;
            }
        }

        $dispute->save();

        // Audit log for dispute resolution
        AuditHelper::log(
            'dispute.resolve',
            Dispute::class,
            $dispute->id,
            ['status' => $oldStatus],
            [
                'status' => $validated['status'],
                'resolution' => $normalizedResolution,
                'resolved_by' => $user->id,
            ],
            $request
        );

        // Notify parties when dispute is resolved
        if ($validated['status'] === 'resolved' && $normalizedResolution) {
            $order = $dispute->order;
            $order->buyer->notify(new DisputeResolved($dispute, $normalizedResolution));
            $order->seller->notify(new DisputeResolved($dispute, $normalizedResolution));
            
            // Emit Discord resolved event
            try {
                DisputeEventEmitter::resolved($dispute);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Discord dispute resolved event failed', [
                    'dispute_id' => $dispute->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Emit updated event for status changes
            try {
                DisputeEventEmitter::updated($dispute, [
                    'old_status' => $oldStatus,
                    'new_status' => $validated['status'],
                    'old_resolution' => $oldResolution,
                    'new_resolution' => $normalizedResolution,
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Discord dispute updated event failed', [
                    'dispute_id' => $dispute->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Handle financial resolution when in escrow
        if ($validated['status'] === 'resolved' && $normalizedResolution) {
            $order = $dispute->order;

            if ($order->status === 'disputed' && $order->escrow_hold_at) {
                DB::transaction(function () use ($order, $normalizedResolution) {
                    if ($normalizedResolution === 'refund_buyer') {
                        $buyerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->buyer_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );

                        if ($buyerWallet->on_hold_balance < $order->amount) {
                            throw new \Exception(MessageHelper::ORDER_INSUFFICIENT_ESCROW);
                        }

                        $buyerWallet->available_balance += $order->amount;
                        $buyerWallet->on_hold_balance -= $order->amount;
                        $buyerWallet->save();

                        $order->status = 'cancelled';
                    } elseif ($normalizedResolution === 'release_to_seller') {
                        $sellerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->seller_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );

                        $buyerWallet = Wallet::lockForUpdate()
                            ->firstOrCreate(
                                ['user_id' => $order->buyer_id],
                                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                            );

                        if ($buyerWallet->on_hold_balance < $order->amount) {
                            throw new \Exception(MessageHelper::ORDER_INSUFFICIENT_ESCROW);
                        }

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

    private function normalizeResolution(?string $resolution): ?string
    {
        return match ($resolution) {
            'buyer' => 'refund_buyer',
            'seller' => 'release_to_seller',
            'refund_buyer', 'release_to_seller' => $resolution,
            default => null,
        };
    }

    /**
     * Cancel a dispute (user can cancel own open dispute)
     */
    public function cancel(Request $request, $id)
    {
        $dispute = Dispute::findOrFail($id);
        $user = $request->user();

        // Only initiator can cancel
        if ($dispute->initiated_by !== $user->id) {
            return response()->json([
                'message' => 'يمكن فقط للمُبلّغ إلغاء النزاع'
            ], 403);
        }

        // Can only cancel if still 'open'
        if ($dispute->status !== 'open') {
            return response()->json([
                'message' => 'لا يمكن إلغاء النزاع بعد بدء المراجعة',
                'error_code' => 'DISPUTE_CANNOT_CANCEL',
            ], 400);
        }

        DB::transaction(function () use ($dispute) {
            // Update dispute status
            $dispute->status = 'closed';
            $dispute->resolution_notes = 'تم الإلغاء من قبل المُبلّغ';
            $dispute->resolved_at = now();
            $dispute->save();

            // Restore order to escrow_hold status
            $order = $dispute->order;
            $order->status = 'escrow_hold';
            $order->save();
        });

        // Audit log
        AuditHelper::log(
            'dispute.cancelled',
            Dispute::class,
            $dispute->id,
            ['status' => 'open'],
            ['status' => 'closed', 'cancelled_by' => $user->id],
            $request
        );

        return response()->json([
            'message' => 'تم إلغاء النزاع بنجاح',
            'dispute' => $dispute->fresh()->load(['order', 'initiator']),
        ]);
    }
}
