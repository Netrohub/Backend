<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Order;
use App\Services\ListingEventEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\MessageHelper;
use App\Helpers\PaginationHelper;
use App\Helpers\AuditHelper;
use App\Services\DiscordBotService;

class ListingController extends Controller
{
    public function __construct(
        private ?DiscordBotService $discordBotService = null
    ) {
        // Initialize DiscordBotService if not injected (for backward compatibility)
        if (!$this->discordBotService) {
            $this->discordBotService = app(DiscordBotService::class);
        }
    }
    // Price limits for listings
    const MIN_LISTING_PRICE = 10; // $10 minimum
    const MAX_LISTING_PRICE = 10000; // $10,000 maximum
    const MAX_IMAGES = 10; // Maximum 10 images per listing
    const MAX_ACTIVE_LISTINGS_PER_USER = 20; // Max active listings per user
    public function index(Request $request)
    {
        // Show both active and sold listings from active (non-deleted) users
        // Sold listings remain visible but are marked as non-accessible
        $query = Listing::with('user')
            ->whereIn('status', ['active', 'sold'])
            ->fromActiveUsers();

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('search') && !empty($request->search)) {
            // Validate and sanitize search parameter
            $validated = $request->validate([
                'search' => 'string|max:255',
            ]);
            $search = $validated['search'] ?? '';

            if (!empty($search)) {
                // Escape special characters for LIKE query to prevent SQL injection
                $search = str_replace(['%', '_'], ['\%', '\_'], $search);
                
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%');
                });
            }
        }

        // Cache key based on query parameters (excluding page for pagination)
        $cacheKey = 'listings_' . md5($request->get('category', '') . $request->get('search', ''));
        $page = $request->get('page', 1);
        $perPage = min($request->get('per_page', 20), 100);
        
        $transformPaginated = function ($paginator) use ($request) {
            $paginator->getCollection()->transform(function (Listing $listing) use ($request) {
                return $this->transformListing($listing);
            });

            return $paginator->toArray();
        };

        // Cache paginated results for 10 minutes
        // Note: Only cache first page with no search to avoid cache bloat
        if ($page === 1 && !$request->has('search')) {
            $listings = Cache::remember($cacheKey, 600, function () use ($query, $request, $transformPaginated) {
                $paginator = PaginationHelper::paginate($query->orderBy('created_at', 'desc'), $request);
                return $transformPaginated($paginator);
            });
        } else {
            // Don't cache search results or paginated pages
            $paginator = PaginationHelper::paginate($query->orderBy('created_at', 'desc'), $request);
            $listings = $transformPaginated($paginator);
        }

        return response()->json($listings);
    }

    /**
     * Get current user's listings only
     * SECURITY: Only returns authenticated user's listings (data isolation)
     */
    public function myListings(Request $request)
    {
        $user = $request->user();

        // Query only this user's listings
        $query = Listing::where('user_id', $user->id);

        // Optional status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Optional search
        if ($request->has('search') && !empty($request->search)) {
            $validated = $request->validate([
                'search' => 'string|max:255',
            ]);
            $search = $validated['search'] ?? '';

            if (!empty($search)) {
                $search = str_replace(['%', '_'], ['\%', '\_'], $search);
                
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', '%' . $search . '%')
                      ->orWhere('description', 'like', '%' . $search . '%');
                });
            }
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        return response()->json(PaginationHelper::paginate($query, $request));
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000', // Max length for description
            'price' => [
                'required',
                'numeric',
                'min:' . self::MIN_LISTING_PRICE,
                'max:' . self::MAX_LISTING_PRICE,
            ],
            'category' => 'required|string|max:100',
            'images' => 'nullable|array|max:' . self::MAX_IMAGES,
            'images.*' => 'url|max:2048', // Each image must be a valid URL
            
            // Account credentials (encrypted)
            'account_email' => 'required|email|max:255',
            'account_password' => 'required|string|max:255',
            
            // Account metadata (server, stove level, helios, etc.)
            'account_metadata' => 'nullable|array',
        ]);

        // Check for duplicate listings (by title similarity)
        $recentListings = Listing::where('user_id', $user->id)
            ->where('created_at', '>', now()->subHours(24))
            ->where('status', 'active')
            ->get();

        foreach ($recentListings as $existing) {
            similar_text(strtolower($validated['title']), strtolower($existing->title), $percent);
            if ($percent > 80) {
                return response()->json([
                    'message' => 'يبدو أن لديك إعلان مماثل بالفعل. لا يمكن نشر نفس الحساب مرتين.',
                    'error_code' => 'DUPLICATE_LISTING_DETECTED',
                    'similar_listing_id' => $existing->id,
                ], 400);
            }
        }

        // Validate price is reasonable (not too cheap for the category)
        if ($validated['price'] < self::MIN_LISTING_PRICE) {
            return response()->json([
                'message' => 'السعر منخفض جداً. الحد الأدنى للسعر هو $' . self::MIN_LISTING_PRICE,
                'error_code' => 'PRICE_TOO_LOW',
            ], 400);
        }

        // SECURITY: Wrap listing creation in transaction to prevent race conditions
        try {
            $listing = DB::transaction(function () use ($user, $validated, $request) {
                // SECURITY: Check max active listings per user
                // Note: PostgreSQL doesn't allow FOR UPDATE with aggregate functions,
                // but the transaction isolation provides sufficient protection
                $activeListingsCount = Listing::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->count();

                if ($activeListingsCount >= self::MAX_ACTIVE_LISTINGS_PER_USER) {
                    throw new \Exception('MAX_ACTIVE_LISTINGS_REACHED');
                }

                // Sanitize HTML content to prevent XSS
                $title = htmlspecialchars(strip_tags($validated['title']), ENT_QUOTES, 'UTF-8');
                $description = htmlspecialchars(strip_tags($validated['description']), ENT_QUOTES, 'UTF-8');
                $category = htmlspecialchars(strip_tags($validated['category']), ENT_QUOTES, 'UTF-8');

                $listing = new Listing([
                    'user_id' => $user->id,
                    'title' => $title,
                    'description' => $description, // NO passwords here!
                    'price' => $validated['price'],
                    'category' => $category,
                    'images' => $validated['images'] ?? [],
                    'status' => 'active',
                    'account_metadata' => $validated['account_metadata'] ?? null,
                ]);

                // Set encrypted credentials (uses accessors/mutators)
                $listing->account_email = $validated['account_email'];
                $listing->account_password = $validated['account_password'];
                
                $listing->save();

                return $listing;
            });
        } catch (\Exception $e) {
            if ($e->getMessage() === 'MAX_ACTIVE_LISTINGS_REACHED') {
                return response()->json([
                    'message' => 'لقد وصلت إلى الحد الأقصى من الإعلانات النشطة (' . self::MAX_ACTIVE_LISTINGS_PER_USER . '). يرجى حذف أو إيقاف بعض الإعلانات القديمة أولاً.',
                    'error_code' => 'MAX_ACTIVE_LISTINGS_REACHED',
                ], 400);
            }
            throw $e;
        }

        // Log listing creation
        AuditHelper::log(
            'listing_created',
            'listings',
            $listing->id,
            null, // oldValues
            [
                'title' => $listing->title,
                'price' => $listing->price,
                'category' => $listing->category,
            ], // newValues
            $request
        );

        // Invalidate listings cache when new listing is created
        Cache::forget('listings_' . md5($listing->category . ''));

        // Reload listing with relationships for response
        $listing->load('user');

        // Send Discord notification (non-blocking, fire and forget)
        try {
            $this->discordBotService?->notifyNewListing([
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->price,
                'category' => $listing->category,
                'images' => $listing->images ?? [],
                'created_at' => $listing->created_at?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the request
            Log::error('Discord bot notification failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Emit Discord event via event emitter (non-blocking)
        try {
            ListingEventEmitter::created($listing);
        } catch (\Exception $e) {
            Log::error('Discord listing event failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($listing, 201);
    }

    public function show($id)
    {
        $listing = Listing::with('user')->findOrFail($id);
        
        // Increment views
        $listing->increment('views');

        $currentUser = request()->user();
        $publicListing = $this->transformListing($listing, true, $currentUser);

        return response()->json($publicListing);
    }

    public function update(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);

        if ($listing->user_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:5000', // Max length for description
            'price' => [
                'sometimes',
                'numeric',
                'min:' . self::MIN_LISTING_PRICE,
                'max:' . self::MAX_LISTING_PRICE,
            ],
            'category' => 'sometimes|string|max:100',
            'images' => 'sometimes|array|max:' . self::MAX_IMAGES,
            'images.*' => 'url|max:2048', // Each image must be a valid URL
            'status' => 'sometimes|in:active,inactive', // Sellers cannot manually set to 'sold' - only via payment confirmation
            'account_email' => 'sometimes|email|max:255',
            'account_password' => 'sometimes|string|max:255',
            'account_metadata' => 'sometimes|array',
        ]);
        
        // Prevent sellers from manually marking listings as sold
        // Status 'sold' can only be set automatically via payment confirmation webhook
        if (isset($validated['status']) && $validated['status'] === 'sold') {
            return response()->json([
                'message' => 'لا يمكنك تحديد الإعلان كمباع يدوياً. يتم تحديده تلقائياً بعد إتمام الدفع.',
                'error_code' => 'CANNOT_MANUAL_MARK_SOLD',
            ], 400);
        }

        // SECURITY: Check for active REAL orders before allowing certain updates
        // payment_intent is NOT a real order - only escrow_hold, disputed, and completed are real orders
        $hasActiveOrders = Order::where('listing_id', $listing->id)
            ->whereIn('status', ['escrow_hold', 'disputed']) // Only REAL orders (payment confirmed)
            ->exists();

        $hasCompletedOrders = Order::where('listing_id', $listing->id)
            ->where('status', 'completed')
            ->exists();

        // SECURITY: Prevent price changes when listing has active orders
        if (isset($validated['price']) && $validated['price'] != $listing->price && $hasActiveOrders) {
            return response()->json([
                'message' => 'لا يمكن تغيير السعر بينما يوجد طلبات نشطة على الإعلان.',
                'error_code' => 'CANNOT_CHANGE_PRICE_WITH_ORDERS',
            ], 400);
        }

        // SECURITY: Prevent credential changes when listing has active or completed orders
        if (($hasActiveOrders || $hasCompletedOrders) && (isset($validated['account_email']) || isset($validated['account_password']))) {
            return response()->json([
                'message' => 'لا يمكن تغيير بيانات الحساب بينما يوجد طلبات نشطة أو مكتملة على الإعلان.',
                'error_code' => 'CANNOT_CHANGE_CREDENTIALS_WITH_ORDERS',
            ], 400);
        }

        // SECURITY: Prevent status change to inactive when listing has active orders
        if (isset($validated['status']) && $validated['status'] === 'inactive' && $hasActiveOrders) {
            return response()->json([
                'message' => 'لا يمكن إيقاف الإعلان بينما يوجد طلبات نشطة عليه.',
                'error_code' => 'CANNOT_DEACTIVATE_WITH_ORDERS',
            ], 400);
        }

        // SECURITY: Prevent metadata changes when listing has active orders
        if (isset($validated['account_metadata']) && $hasActiveOrders) {
            return response()->json([
                'message' => 'لا يمكن تغيير تفاصيل الإعلان بينما يوجد طلبات نشطة عليه.',
                'error_code' => 'CANNOT_CHANGE_DETAILS_WITH_ORDERS',
            ], 400);
        }

        // Sanitize HTML content to prevent XSS
        if (isset($validated['title'])) {
            $validated['title'] = htmlspecialchars(strip_tags($validated['title']), ENT_QUOTES, 'UTF-8');
        }
        if (isset($validated['description'])) {
            $validated['description'] = htmlspecialchars(strip_tags($validated['description']), ENT_QUOTES, 'UTF-8');
        }
        if (isset($validated['category'])) {
            $validated['category'] = htmlspecialchars(strip_tags($validated['category']), ENT_QUOTES, 'UTF-8');
        }

        $oldCategory = $listing->category;
        $oldStatus = $listing->status;

        // Update basic fields
        $basicFields = ['title', 'description', 'price', 'category', 'images', 'status', 'account_metadata'];
        foreach ($basicFields as $field) {
            if (isset($validated[$field])) {
                $listing->$field = $validated[$field];
            }
        }

        // Update encrypted credentials if provided
        if (isset($validated['account_email'])) {
            $listing->account_email = $validated['account_email'];
        }
        if (isset($validated['account_password'])) {
            $listing->account_password = $validated['account_password'];
        }

        try {
            $listing->save();
        } catch (\Exception $e) {
            Log::error('Failed to update listing: ' . $e->getMessage(), [
                'listing_id' => $listing->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'فشل تحديث الإعلان. يرجى المحاولة مرة أخرى.',
                'error_code' => 'UPDATE_FAILED',
            ], 500);
        }

        // Log update
        AuditHelper::log(
            'listing_updated',
            'listings',
            $listing->id,
            null, // oldValues
            ['updated_fields' => array_keys($validated)], // newValues
            $request
        );

        // Track status change for Discord event
        $newStatus = $listing->status;
        
        // Invalidate cache for both old and new categories if category changed
        if (isset($validated['category']) && $validated['category'] !== $oldCategory) {
            Cache::forget('listings_' . md5($oldCategory . ''));
            Cache::forget('listings_' . md5($validated['category'] . ''));
        } else {
            Cache::forget('listings_' . md5($listing->category . ''));
        }

        // Emit Discord events (non-blocking)
        try {
            if (isset($validated['status']) && $oldStatus !== $newStatus) {
                ListingEventEmitter::statusChanged($listing, $oldStatus, $newStatus);
            } else {
                ListingEventEmitter::updated($listing, array_keys($validated));
            }
        } catch (\Exception $e) {
            Log::error('Discord listing event failed', [
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($listing->load('user'));
    }

    public function destroy(Request $request, $id)
    {
        // SECURITY: Wrap deletion in transaction with pessimistic locking to prevent race conditions
        try {
            DB::transaction(function () use ($id, $request) {
                // SECURITY: Lock listing row to prevent concurrent deletion attempts
                $listing = Listing::lockForUpdate()->findOrFail($id);

                if ($listing->user_id !== $request->user()->id) {
                    throw new \Exception('UNAUTHORIZED');
                }

                // SECURITY: Check if listing has active REAL orders (exclude payment_intent - those are not orders yet)
                // Check happens AFTER lock to prevent race conditions
                $activeOrders = Order::where('listing_id', $listing->id)
                    ->whereIn('status', ['escrow_hold', 'disputed', 'completed']) // Only real orders block deletion
                    ->exists();

                if ($activeOrders) {
                    throw new \Exception('HAS_ACTIVE_ORDERS');
                }

                $category = $listing->category;
                
                // Log deletion
                AuditHelper::log(
                    'listing_deleted',
                    'listings',
                    $listing->id,
                    null, // oldValues
                    ['title' => $listing->title], // newValues
                    $request
                );

                $listing->delete();

                // Invalidate listings cache when listing is deleted
                Cache::forget('listings_' . md5($category . ''));
            });
            
            return response()->json(['message' => 'Listing deleted successfully.']);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'UNAUTHORIZED') {
                return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
            }
            if ($e->getMessage() === 'HAS_ACTIVE_ORDERS') {
                return response()->json([
                    'message' => 'لا يمكن حذف الإعلان لأن لديه طلبات نشطة. يرجى إنهاء أو إلغاء الطلبات أولاً.',
                    'error_code' => 'HAS_ACTIVE_ORDERS',
                ], 400);
            }
            throw $e;
        }
    }

    /**
     * Get account credentials for a listing
     * Only accessible by: owner, buyer after purchase, admin
     */
    public function getCredentials(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);
        $user = $request->user();

        $isOwner = $listing->user_id === $user->id;
        $isAdmin = $user->isAdmin();

        $buyerOrder = null;
        $canAccessCredentials = false;
        $canViewBillImages = false;

        if ($isOwner || $isAdmin) {
            $canAccessCredentials = true;
            $canViewBillImages = true;
        } else {
            $buyerOrder = Order::where('listing_id', $listing->id)
                ->where('buyer_id', $user->id)
                ->latest()
                ->first();

            if ($buyerOrder) {
                if (in_array($buyerOrder->status, ['escrow_hold', 'completed'], true)) {
                    $canAccessCredentials = true;
                }

                if ($buyerOrder->status === 'completed') {
                    $canViewBillImages = true;
                }
            }
        }

        if (!$canAccessCredentials) {
            return response()->json([
                'message' => 'غير مصرح لك بالوصول إلى بيانات الحساب. يجب إتمام عملية الشراء أولاً.',
                'error_code' => 'CREDENTIALS_ACCESS_DENIED',
            ], 403);
        }

        // Prepare metadata while enforcing bill image visibility rules
        $metadata = $listing->account_metadata ?? [];
        if (!$canViewBillImages && is_array($metadata) && array_key_exists('bill_images', $metadata)) {
            unset($metadata['bill_images']);
        }

        // Log credential access
        AuditHelper::log(
            'listing_credentials_accessed',
            'listings',
            $listing->id,
            null, // oldValues (not applicable for access logs)
            [
                'accessed_by_owner' => $isOwner,
                'accessed_by_admin' => $isAdmin,
                'buyer_order_status' => $buyerOrder?->status,
                'bill_images_unlocked' => $canViewBillImages,
            ],
            $request, // Request instance
            $user->id // userId
        );

        return response()->json([
            'listing_id' => $listing->id,
            'account_email' => $listing->account_email,
            'account_password' => $listing->account_password,
            'account_metadata' => $metadata,
            'bill_images_unlocked' => $canViewBillImages,
        ]);
    }

    /**
     * Transform a listing for public API responses by stripping sensitive data.
     */
    private function transformListing(Listing $listing, bool $includeCredentialFlag = false, $currentUser = null): array
    {
        $listing->loadMissing('user');

        $data = $listing->toArray();

        // Never expose encrypted or decrypted credentials in public responses
        unset($data['account_email_encrypted'], $data['account_password_encrypted']);
        unset($data['account_email'], $data['account_password']);

        // Remove bill images from metadata unless explicitly unlocked elsewhere
        if (isset($data['account_metadata']) && is_array($data['account_metadata'])) {
            unset($data['account_metadata']['bill_images']);
        }

        // Provide a trimmed seller profile instead of the entire user model
        $data['user'] = null;
        if ($listing->relationLoaded('user') && $listing->user) {
            $seller = $listing->user;
            $data['user'] = [
                'id' => $seller->id,
                'username' => $seller->username,
                'display_name' => $seller->display_name,
                'name' => $seller->name, // Keep for backward compatibility
                'avatar' => $seller->avatar,
                'is_verified' => (bool) $seller->is_verified,
                'average_rating' => $seller->average_rating,
                'total_reviews' => $seller->total_reviews,
            ];
        }

        if ($includeCredentialFlag) {
            $canAccessCredentials = $currentUser && $listing->canAccessCredentials($currentUser);
            $data['credentials_available_after_purchase'] = !$canAccessCredentials;
        }

        return $data;
    }
}
