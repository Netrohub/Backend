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
        $query = AuctionListing::with(['user', 'currentBidder'])
            ->withCount('bids');

        $user = $request->user();
        $isAdmin = $user && $user->isAdmin();

        // Log for debugging
        Log::info('Auction listing request', [
            'user_id' => $user?->id,
            'is_admin' => $isAdmin,
            'status_filter' => $request->input('status'),
            'has_status' => $request->has('status'),
        ]);

        // Filter by status
        if ($request->has('status') && $request->input('status') !== 'all') {
            $status = $request->validate(['status' => Rule::in(['pending_approval', 'approved', 'live', 'ended', 'cancelled'])])['status'];
            $query->where('status', $status);
            
            // Security: non-admins cannot see pending_approval or cancelled
            if (!$isAdmin && in_array($status, ['pending_approval', 'cancelled'])) {
                return response()->json([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 15,
                        'total' => 0,
                    ],
                ]);
            }
        } else {
            // No status filter or "all" - show based on user role
            if ($isAdmin) {
                // Admins see all statuses when no filter or "all" is selected
                // This allows them to see pending_approval auctions
                // No additional filter needed
            } else {
                // Non-admins: show live and approved auctions only
                $query->whereIn('status', ['live', 'approved']);
            }
        }

        // Additional security: non-admins can never see pending_approval or cancelled
        // (Only applies if no status filter was set, since we already filtered above)
        if (!$isAdmin && (!$request->has('status') || $request->input('status') === 'all')) {
            $query->whereIn('status', ['live', 'approved', 'ended']);
        }

        // Order by: live auctions first, then by end date
        $query->orderByRaw("CASE WHEN status = 'live' THEN 0 ELSE 1 END")
            ->orderBy('ends_at', 'asc')
            ->orderBy('created_at', 'desc');

        // Debug: Log the SQL query before pagination
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        Log::info('Auction query SQL', [
            'sql' => $sql,
            'bindings' => $bindings,
        ]);
        
        // Debug: Count total auctions by status
        $statusCounts = AuctionListing::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        Log::info('Total auctions by status', $statusCounts);
        
        $result = PaginationHelper::paginate($query, $request);
        
        // Convert paginator to array for proper JSON response
        $responseData = [
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
            'links' => [
                'first' => $result->url(1),
                'last' => $result->url($result->lastPage()),
                'prev' => $result->previousPageUrl(),
                'next' => $result->nextPageUrl(),
            ],
        ];
        
        Log::info('Auction listing response', [
            'total' => $responseData['meta']['total'],
            'count' => count($responseData['data']),
            'status_filter' => $request->input('status'),
            'is_admin' => $isAdmin,
            'user_id' => $user?->id,
            'status_counts' => $statusCounts,
        ]);

        return response()->json($responseData);
    }

    /**
     * Get single auction listing details
     */
    public function show($id)
    {
        $auction = AuctionListing::with([
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

        // Hide credentials from public (already hidden via $hidden in model)
        $auction->makeHidden(['account_email_encrypted', 'account_password_encrypted']);

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

        // Log received data for debugging
        Log::info('Auction creation request', [
            'user_id' => $user->id,
            'request_data' => $request->all(),
            'request_json' => $request->json()->all(),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method(),
        ]);

        // Check if only listing_id is provided (backward compatibility with old frontend)
        $hasListingId = $request->has('listing_id') && $request->filled('listing_id');
        $hasDirectFields = $request->has('title') && $request->has('description') && $request->has('price');
        
        if ($hasListingId && !$hasDirectFields) {
            // Old flow: fetch listing data from existing listing
            $listing = Listing::findOrFail($request->input('listing_id'));
            
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
            
            // Use listing data to create auction
            // Note: account_email and account_password might be null if not set
            $accountEmail = $listing->account_email;
            $accountPassword = $listing->account_password;
            
            if (!$accountEmail || !$accountPassword) {
                return response()->json([
                    'message' => 'The listing must have account credentials to create an auction.',
                    'error_code' => 'MISSING_CREDENTIALS',
                ], 400);
            }
            
            $validated = [
                'listing_id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->price,
                'category' => $listing->category,
                'images' => $listing->images ?? [],
                'account_email' => $accountEmail,
                'account_password' => $accountPassword,
                'account_metadata' => $listing->account_metadata ?? null,
            ];
        } else {
            // New flow: validate direct fields
            try {
                $validated = $request->validate([
                    // Listing data (directly in auction_listings)
                    'title' => 'required|string|max:255',
                    'description' => 'required|string|max:5000',
                    'price' => 'required|numeric|min:10|max:10000',
                    'category' => ['required', 'string', Rule::in(['wos_accounts'])],
                    'images' => 'nullable|array|max:10',
                    'images.*' => 'nullable|url|max:2048',
                    'account_email' => 'required|email|max:255',
                    'account_password' => 'required|string|max:255',
                    'account_metadata' => 'nullable|array',
                    
                    // Optional: can still link to existing listing if provided
                    'listing_id' => 'nullable|exists:listings,id',
                ], [
                    'title.required' => 'Title is required',
                    'description.required' => 'Description is required',
                    'price.required' => 'Price is required',
                    'price.numeric' => 'Price must be a number',
                    'price.min' => 'Price must be at least $10',
                    'price.max' => 'Price must not exceed $10,000',
                    'category.required' => 'Category is required',
                    'category.in' => 'Only Whiteout Survival accounts can be listed for auction',
                    'account_email.required' => 'Account email is required',
                    'account_email.email' => 'Account email must be a valid email address',
                    'account_password.required' => 'Account password is required',
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Log::error('Auction validation failed', [
                    'errors' => $e->errors(),
                    'request_data' => $request->all(),
                ]);
                throw $e;
            }

            // Only WOS accounts allowed
            if ($validated['category'] !== 'wos_accounts') {
                return response()->json([
                    'message' => 'Only Whiteout Survival accounts can be listed for auction.',
                    'error_code' => 'INVALID_CATEGORY',
                ], 400);
            }

            // If listing_id provided, verify ownership
            if (isset($validated['listing_id'])) {
                $listing = Listing::findOrFail($validated['listing_id']);
                if ($listing->user_id !== $user->id) {
                    return response()->json([
                        'message' => 'You can only create auctions for your own listings.',
                        'error_code' => 'UNAUTHORIZED',
                    ], 403);
                }
            }
        }

        // Create auction listing directly (pending approval)
        $auction = new AuctionListing([
            'listing_id' => $validated['listing_id'] ?? null,
            'user_id' => $user->id,
            'title' => htmlspecialchars(strip_tags($validated['title']), ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars(strip_tags($validated['description']), ENT_QUOTES, 'UTF-8'),
            'price' => $validated['price'],
            'category' => $validated['category'],
            'images' => $validated['images'] ?? [],
            'account_metadata' => $validated['account_metadata'] ?? null,
            'status' => 'pending_approval',
        ]);

        // Set encrypted credentials
        $auction->account_email = $validated['account_email'];
        $auction->account_password = $validated['account_password'];
        
        $auction->save();

        AuditHelper::log(
            'auction.created',
            AuctionListing::class,
            $auction->id,
            null,
            [
                'auction_id' => $auction->id,
                'user_id' => $user->id,
                'status' => 'pending_approval',
            ],
            $request
        );

        return response()->json($auction->load(['user']), 201);
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

        AuditHelper::log(
            'auction.approved',
            AuctionListing::class,
            $auction->id,
            [
                'status' => 'pending_approval',
            ],
            [
                'auction_id' => $auction->id,
                'status' => $auction->status,
                'starting_bid' => $validated['starting_bid'],
                'admin_id' => $admin->id,
            ],
            $request
        );

        return response()->json($auction->fresh(['user', 'approvedBy']));
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

        $auction = AuctionListing::findOrFail($id);

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

            AuditHelper::log(
                'auction.bid_placed',
                Bid::class,
                $bid->id,
                null,
                [
                    'auction_id' => $auction->id,
                    'bid_id' => $bid->id,
                    'amount' => $validated['amount'],
                    'user_id' => $user->id,
                ],
                $request
            );

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
    public function endAuction(Request $request, $id)
    {
        $auction = AuctionListing::with(['winningBid.user'])->findOrFail($id);

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

        return DB::transaction(function () use ($auction, $request) {
            $auction->update(['status' => 'ended']);

            // If there's a winning bid, create order
            if ($auction->winningBid) {
                // Create order (listing_id can be null for auction-only orders)
                $order = \App\Models\Order::create([
                    'listing_id' => $auction->listing_id, // May be null for auction-only listings
                    'buyer_id' => $auction->current_bidder_id,
                    'seller_id' => $auction->user_id,
                    'amount' => $auction->current_bid,
                    'status' => 'payment_intent', // Buyer needs to complete payment
                ]);

                // Apply deposit to order
                $winningBid = $auction->winningBid;
                $winningBid->update(['deposit_status' => 'applied']);

                AuditHelper::log(
                    'auction.ended',
                    AuctionListing::class,
                    $auction->id,
                    [
                        'status' => 'live',
                    ],
                    [
                        'auction_id' => $auction->id,
                        'status' => 'ended',
                        'order_id' => $order->id,
                        'winning_bid_id' => $winningBid->id,
                    ],
                    $request
                );

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

