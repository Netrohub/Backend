<?php

namespace App\Http\Controllers;

use App\Models\AuctionListing;
use App\Models\Bid;
use App\Models\Listing;
use App\Models\Wallet;
use App\Helpers\PaginationHelper;
use App\Helpers\AuditHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class AuctionController extends Controller
{
    /**
     * List all auction listings (public)
     */
    public function index(Request $request)
    {
        $query = AuctionListing::with(['listing', 'user', 'currentBidder'])
            ->withCount('bids');

        // Filter by status
        if ($request->has('status')) {
            $status = $request->validate(['status' => Rule::in(['pending_approval', 'approved', 'live', 'ended', 'cancelled'])])['status'];
            $query->where('status', $status);
        } else {
            // Default: show live and approved auctions
            $query->whereIn('status', ['live', 'approved']);
        }

        // Only show approved/live auctions to non-admins
        if (!$request->user() || !$request->user()->isAdmin()) {
            $query->whereIn('status', ['live', 'approved', 'ended']);
        }

        // Order by: live auctions first, then by end date
        $query->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END")
            ->orderBy('ends_at', 'asc')
            ->orderBy('created_at', 'desc');

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    /**
     * Get single auction listing details
     */
    public function show($id)
    {
        $auction = AuctionListing::with([
            'listing',
            'user',
            'currentBidder',
            'bids' => function($query) {
                $query->with('user:id,name,username,avatar')
                    ->orderBy('amount', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->limit(20); // Last 20 bids
            },
            'winningBid.user:id,name,username,avatar'
        ])->findOrFail($id);

        // Hide credentials from public
        if ($auction->listing) {
            $auction->listing->makeHidden(['account_email_encrypted', 'account_password_encrypted']);
        }

        return response()->json($auction);
    }

    /**
     * Create auction listing (seller submits for approval)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Only verified users can create auction listings
        if (!$user->is_verified) {
            return response()->json([
                'message' => 'You must be verified to create auction listings.',
                'error_code' => 'VERIFICATION_REQUIRED',
            ], 403);
        }

        $validated = $request->validate([
            'listing_id' => 'required|exists:listings,id',
        ]);

        $listing = Listing::findOrFail($validated['listing_id']);

        // Verify ownership
        if ($listing->user_id !== $user->id) {
            return response()->json([
                'message' => 'You can only create auctions for your own listings.',
                'error_code' => 'UNAUTHORIZED',
            ], 403);
        }

        // Only WOS accounts allowed
        if ($listing->category !== 'wos_accounts') {
            return response()->json([
                'message' => 'Only Whiteout Survival accounts can be listed for auction.',
                'error_code' => 'INVALID_CATEGORY',
            ], 400);
        }

        // Check if listing already has an auction
        $existingAuction = AuctionListing::where('listing_id', $listing->id)
            ->whereIn('status', ['pending_approval', 'approved', 'live'])
            ->first();

        if ($existingAuction) {
            return response()->json([
                'message' => 'This listing already has an active auction.',
                'error_code' => 'AUCTION_EXISTS',
            ], 400);
        }

        // Create auction listing (pending approval)
        $auction = AuctionListing::create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'status' => 'pending_approval',
        ]);

        AuditHelper::log('auction.created', [
            'auction_id' => $auction->id,
            'listing_id' => $listing->id,
            'user_id' => $user->id,
        ], $user);

        return response()->json($auction->load(['listing', 'user']), 201);
    }

    /**
     * Admin: Approve auction listing
     */
    public function approve(Request $request, $id)
    {
        $admin = $request->user();

        if (!$admin->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'starting_bid' => 'required|numeric|min:0',
            'starts_at' => 'nullable|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
            'admin_notes' => 'nullable|string|max:1000',
            'is_maxed_account' => 'boolean',
        ]);

        $auction = AuctionListing::findOrFail($id);

        if ($auction->status !== 'pending_approval') {
            return response()->json([
                'message' => 'Auction is not pending approval.',
                'error_code' => 'INVALID_STATUS',
            ], 400);
        }

        DB::transaction(function () use ($auction, $validated, $admin) {
            $auction->update([
                'status' => 'approved',
                'starting_bid' => $validated['starting_bid'],
                'current_bid' => $validated['starting_bid'],
                'starts_at' => $validated['starts_at'] ?? now(),
                'ends_at' => $validated['ends_at'],
                'admin_notes' => $validated['admin_notes'] ?? null,
                'is_maxed_account' => $validated['is_maxed_account'] ?? false,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            // Auto-start if starts_at is now or past
            if ($auction->starts_at <= now()) {
                $auction->update(['status' => 'live']);
            }
        });

        AuditHelper::log('auction.approved', [
            'auction_id' => $auction->id,
            'starting_bid' => $validated['starting_bid'],
            'admin_id' => $admin->id,
        ], $admin);

        return response()->json($auction->fresh(['listing', 'user', 'approvedBy']));
    }

    /**
     * Place a bid
     */
    public function placeBid(Request $request, $id)
    {
        $user = $request->user();

        // Only verified users can bid
        if (!$user->is_verified) {
            return response()->json([
                'message' => 'You must be verified to place bids.',
                'error_code' => 'VERIFICATION_REQUIRED',
            ], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'deposit_amount' => 'required|numeric|min:0',
        ]);

        $auction = AuctionListing::with('listing')->findOrFail($id);

        // Check if auction is live
        if (!$auction->isLive()) {
            return response()->json([
                'message' => 'This auction is not currently live.',
                'error_code' => 'AUCTION_NOT_LIVE',
            ], 400);
        }

        // Check minimum bid
        $minBid = $auction->current_bid ? $auction->current_bid + 1 : $auction->starting_bid;
        if ($validated['amount'] < $minBid) {
            return response()->json([
                'message' => "Minimum bid is {$minBid}.",
                'error_code' => 'BID_TOO_LOW',
            ], 400);
        }

        // Seller cannot bid on their own auction
        if ($auction->user_id === $user->id) {
            return response()->json([
                'message' => 'You cannot bid on your own auction.',
                'error_code' => 'CANNOT_BID_OWN_AUCTION',
            ], 400);
        }

        // Get or create wallet
        $wallet = Wallet::lockForUpdate()
            ->firstOrCreate(
                ['user_id' => $user->id],
                ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
            );

        // Check deposit availability
        if ($wallet->available_balance < $validated['deposit_amount']) {
            return response()->json([
                'message' => 'Insufficient balance for deposit.',
                'error_code' => 'INSUFFICIENT_BALANCE',
            ], 400);
        }

        return DB::transaction(function () use ($auction, $user, $validated, $wallet) {
            // Hold deposit in escrow
            $wallet->available_balance -= $validated['deposit_amount'];
            $wallet->on_hold_balance += $validated['deposit_amount'];
            $wallet->save();

            // Mark previous winning bid as outbid
            if ($auction->current_bidder_id) {
                Bid::where('auction_listing_id', $auction->id)
                    ->where('is_winning_bid', true)
                    ->update([
                        'is_winning_bid' => false,
                        'is_outbid' => true,
                        'outbid_at' => now(),
                    ]);
            }

            // Create new bid
            $bid = Bid::create([
                'auction_listing_id' => $auction->id,
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'deposit_amount' => $validated['deposit_amount'],
                'deposit_status' => 'held',
                'is_winning_bid' => true,
            ]);

            // Update auction
            $auction->update([
                'current_bid' => $validated['amount'],
                'current_bidder_id' => $user->id,
                'bid_count' => $auction->bid_count + 1,
            ]);

            AuditHelper::log('auction.bid_placed', [
                'auction_id' => $auction->id,
                'bid_id' => $bid->id,
                'amount' => $validated['amount'],
                'user_id' => $user->id,
            ], $user);

            return response()->json([
                'bid' => $bid->load('user:id,name,username,avatar'),
                'auction' => $auction->fresh(['currentBidder']),
            ], 201);
        });
    }

    /**
     * Get bids for an auction
     */
    public function getBids(Request $request, $id)
    {
        $auction = AuctionListing::findOrFail($id);

        $query = Bid::where('auction_listing_id', $auction->id)
            ->with('user:id,name,username,avatar')
            ->orderBy('amount', 'desc')
            ->orderBy('created_at', 'desc');

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    /**
     * Refund outbid deposits
     */
    public function refundOutbidDeposits($id)
    {
        $auction = AuctionListing::findOrFail($id);

        $outbidBids = Bid::where('auction_listing_id', $auction->id)
            ->where('is_outbid', true)
            ->where('deposit_status', 'held')
            ->with('user')
            ->get();

        $refunded = 0;

        DB::transaction(function () use ($outbidBids, &$refunded) {
            foreach ($outbidBids as $bid) {
                $wallet = Wallet::lockForUpdate()
                    ->firstOrCreate(
                        ['user_id' => $bid->user_id],
                        ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                    );

                // Refund deposit
                $wallet->on_hold_balance -= $bid->deposit_amount;
                $wallet->available_balance += $bid->deposit_amount;
                $wallet->save();

                $bid->update([
                    'deposit_status' => 'refunded',
                ]);

                $refunded++;
            }
        });

        return response()->json([
            'message' => "Refunded {$refunded} deposits.",
            'refunded_count' => $refunded,
        ]);
    }

    /**
     * End auction and create order for winner
     */
    public function endAuction($id)
    {
        $auction = AuctionListing::with(['listing', 'winningBid.user'])->findOrFail($id);

        if ($auction->status === 'ended') {
            return response()->json([
                'message' => 'Auction is already ended.',
                'error_code' => 'ALREADY_ENDED',
            ], 400);
        }

        if (!$auction->isEnded() && !$auction->ends_at->isPast()) {
            return response()->json([
                'message' => 'Auction has not ended yet.',
                'error_code' => 'NOT_ENDED',
            ], 400);
        }

        return DB::transaction(function () use ($auction) {
            $auction->update(['status' => 'ended']);

            // If there's a winning bid, create order
            if ($auction->winningBid) {
                // Create order similar to regular listings
                $order = \App\Models\Order::create([
                    'listing_id' => $auction->listing_id,
                    'buyer_id' => $auction->current_bidder_id,
                    'seller_id' => $auction->user_id,
                    'amount' => $auction->current_bid,
                    'status' => 'payment_intent', // Buyer needs to complete payment
                ]);

                // Apply deposit to order
                $winningBid = $auction->winningBid;
                $winningBid->update(['deposit_status' => 'applied']);

                AuditHelper::log('auction.ended', [
                    'auction_id' => $auction->id,
                    'order_id' => $order->id,
                    'winning_bid_id' => $winningBid->id,
                ]);

                return response()->json([
                    'auction' => $auction->fresh(),
                    'order' => $order,
                ]);
            }

            // No bids - just end the auction
            return response()->json([
                'auction' => $auction->fresh(),
                'message' => 'Auction ended with no bids.',
            ]);
        });
    }
}

