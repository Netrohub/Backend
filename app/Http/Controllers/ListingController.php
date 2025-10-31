<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\MessageHelper;
use App\Helpers\PaginationHelper;

class ListingController extends Controller
{
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000', // Max length for description
            'price' => 'required|numeric|min:0',
            'category' => 'required|string|max:100',
            'images' => 'nullable|array|max:10', // Maximum 10 images
            'images.*' => 'url|max:2048', // Each image must be a valid URL, max 2048 chars
        ]);

        // Sanitize HTML content to prevent XSS
        $title = htmlspecialchars(strip_tags($validated['title']), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(strip_tags($validated['description']), ENT_QUOTES, 'UTF-8');
        $category = htmlspecialchars(strip_tags($validated['category']), ENT_QUOTES, 'UTF-8');

        $listing = Listing::create([
            'user_id' => $request->user()->id,
            'title' => $title,
            'description' => $description,
            'price' => $validated['price'],
            'category' => $category,
            'images' => $validated['images'] ?? [],
            'status' => 'active',
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

        return response()->json($listing);
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
            'price' => 'sometimes|numeric|min:0',
            'category' => 'sometimes|string|max:100',
            'images' => 'sometimes|array|max:10', // Maximum 10 images
            'images.*' => 'url|max:2048', // Each image must be a valid URL, max 2048 chars
            'status' => 'sometimes|in:active,inactive',
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
        $listing->update($validated);

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

        $category = $listing->category;
        $listing->delete();

        // Invalidate listings cache when listing is deleted
        Cache::forget('listings_' . md5($category . ''));

        return response()->json(['message' => 'Listing deleted successfully.']);
    }
}
