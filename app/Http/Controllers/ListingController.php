<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\MessageHelper;
use App\Helpers\PaginationHelper;
use App\Helpers\AuditHelper;

class ListingController extends Controller
{
    // Price limits for listings
    const MIN_LISTING_PRICE = 10; // $10 minimum
    const MAX_LISTING_PRICE = 10000; // $10,000 maximum
    const MAX_IMAGES = 10; // Maximum 10 images per listing
    const MAX_ACTIVE_LISTINGS_PER_USER = 20; // Max active listings per user
    public function index(Request $request)
    {
        // Only show listings from active (non-deleted) users
        $query = Listing::with('user')
            ->where('status', 'active')
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
        
        // Cache paginated results for 10 minutes
        // Note: Only cache first page to avoid cache bloat
        if ($page === 1 && !$request->has('search')) {
            $listings = Cache::remember($cacheKey, 600, function () use ($query, $request) {
                return PaginationHelper::paginate($query->orderBy('created_at', 'desc'), $request);
            });
        } else {
            // Don't cache search results or paginated pages
            $listings = PaginationHelper::paginate($query->orderBy('created_at', 'desc'), $request);
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

        // Check max active listings per user
        $activeListingsCount = Listing::where('user_id', $user->id)
            ->where('status', 'active')
            ->count();

        if ($activeListingsCount >= self::MAX_ACTIVE_LISTINGS_PER_USER) {
            return response()->json([
                'message' => 'لقد وصلت إلى الحد الأقصى من الإعلانات النشطة (' . self::MAX_ACTIVE_LISTINGS_PER_USER . '). يرجى حذف أو إيقاف بعض الإعلانات القديمة أولاً.',
                'error_code' => 'MAX_ACTIVE_LISTINGS_REACHED',
            ], 400);
        }

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

        // Log listing creation
        AuditHelper::log('listing_created', 'listings', $listing->id, $user->id, [
            'title' => $listing->title,
            'price' => $listing->price,
            'category' => $listing->category,
        ]);

        // Invalidate listings cache when new listing is created
        Cache::forget('listings_' . md5($category . ''));

        return response()->json($listing->load('user'), 201);
    }

    public function show($id)
    {
        $listing = Listing::with('user')->findOrFail($id);
        
        // Increment views
        $listing->increment('views');

        // Hide sensitive data from public view
        $publicListing = $listing->toArray();
        
        // Remove encrypted credential fields (never expose these!)
        unset($publicListing['account_email_encrypted']);
        unset($publicListing['account_password_encrypted']);

        // Check if current user can access credentials
        $currentUser = request()->user();
        $canAccessCredentials = $currentUser && $listing->canAccessCredentials($currentUser);

        if (!$canAccessCredentials) {
            // Remove credentials info completely for non-authorized users
            // They should use the /listings/{id}/credentials endpoint after purchase
            unset($publicListing['account_email']);
            unset($publicListing['account_password']);
            
            // Add flag indicating credentials are available after purchase
            $publicListing['credentials_available_after_purchase'] = true;
        }

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
            'status' => 'sometimes|in:active,inactive,sold',
            'account_email' => 'sometimes|email|max:255',
            'account_password' => 'sometimes|string|max:255',
            'account_metadata' => 'sometimes|array',
        ]);

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
        AuditHelper::log('listing_updated', 'listings', $listing->id, $request->user()->id, [
            'updated_fields' => array_keys($validated),
        ]);

        // Invalidate cache for both old and new categories if category changed
        if (isset($validated['category']) && $validated['category'] !== $oldCategory) {
            Cache::forget('listings_' . md5($oldCategory . ''));
            Cache::forget('listings_' . md5($validated['category'] . ''));
        } else {
            Cache::forget('listings_' . md5($listing->category . ''));
        }

        return response()->json($listing->load('user'));
    }

    public function destroy(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);

        if ($listing->user_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        // Check if listing has active orders
        $activeOrders = Order::where('listing_id', $listing->id)
            ->whereIn('status', ['pending', 'escrow_hold', 'in_dispute'])
            ->exists();

        if ($activeOrders) {
            return response()->json([
                'message' => 'لا يمكن حذف الإعلان لأن لديه طلبات نشطة. يرجى إنهاء أو إلغاء الطلبات أولاً.',
                'error_code' => 'HAS_ACTIVE_ORDERS',
            ], 400);
        }

        $category = $listing->category;
        
        // Log deletion
        AuditHelper::log('listing_deleted', 'listings', $listing->id, $request->user()->id, [
            'title' => $listing->title,
        ]);

        $listing->delete();

        // Invalidate listings cache when listing is deleted
        Cache::forget('listings_' . md5($category . ''));

        return response()->json(['message' => 'Listing deleted successfully.']);
    }

    /**
     * Get account credentials for a listing
     * Only accessible by: owner, buyer after purchase, admin
     */
    public function getCredentials(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);
        $user = $request->user();

        if (!$listing->canAccessCredentials($user)) {
            return response()->json([
                'message' => 'غير مصرح لك بالوصول إلى بيانات الحساب. يجب إتمام عملية الشراء أولاً.',
                'error_code' => 'CREDENTIALS_ACCESS_DENIED',
            ], 403);
        }

        // Log credential access
        AuditHelper::log('listing_credentials_accessed', 'listings', $listing->id, $user->id, [
            'accessed_by_owner' => $listing->user_id === $user->id,
            'accessed_by_admin' => $user->isAdmin(),
        ]);

        return response()->json([
            'listing_id' => $listing->id,
            'account_email' => $listing->account_email,
            'account_password' => $listing->account_password,
            'account_metadata' => $listing->account_metadata,
        ]);
    }
}
