<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Helpers\PaginationHelper;

class ReviewController extends Controller
{
    /**
     * Get reviews for a seller
     */
    public function index(Request $request, $sellerId)
    {
        $query = Review::where('seller_id', $sellerId)
            ->with(['reviewer.kycVerification']);

        // Filter by rating
        if ($request->has('rating') && $request->rating !== 'all') {
            $query->where('rating', $request->rating);
        }

        // Sort
        $sortBy = $request->get('sort', 'recent');
        switch ($sortBy) {
            case 'recent':
                $query->orderBy('created_at', 'desc');
                break;
            case 'helpful':
                $query->withCount('helpfulVoters')
                    ->orderBy('helpful_voters_count', 'desc');
                break;
            case 'rating-high':
                $query->orderBy('rating', 'desc');
                break;
            case 'rating-low':
                $query->orderBy('rating', 'asc');
                break;
        }

        $reviews = PaginationHelper::paginate($query, $request);

        // Add helpful status for current user
        if ($request->user()) {
            $reviews->getCollection()->transform(function ($review) use ($request) {
                $review->user_found_helpful = $review->foundHelpfulByUser($request->user()->id);
                $review->helpful_count = $review->helpfulVoters()->count();
                return $review;
            });
        }

        return response()->json($reviews);
    }

    /**
     * Get review statistics for a seller
     */
    public function stats($sellerId)
    {
        $stats = Review::where('seller_id', $sellerId)
            ->select(
                DB::raw('AVG(rating) as average_rating'),
                DB::raw('COUNT(*) as total_reviews'),
                DB::raw('COUNT(CASE WHEN rating = 5 THEN 1 END) as rating_5'),
                DB::raw('COUNT(CASE WHEN rating = 4 THEN 1 END) as rating_4'),
                DB::raw('COUNT(CASE WHEN rating = 3 THEN 1 END) as rating_3'),
                DB::raw('COUNT(CASE WHEN rating = 2 THEN 1 END) as rating_2'),
                DB::raw('COUNT(CASE WHEN rating = 1 THEN 1 END) as rating_1')
            )
            ->first();

        return response()->json([
            'average_rating' => round($stats->average_rating ?? 0, 1),
            'total_reviews' => $stats->total_reviews ?? 0,
            'rating_distribution' => [
                5 => $stats->rating_5 ?? 0,
                4 => $stats->rating_4 ?? 0,
                3 => $stats->rating_3 ?? 0,
                2 => $stats->rating_2 ?? 0,
                1 => $stats->rating_1 ?? 0,
            ],
        ]);
    }

    /**
     * Create a new review
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'seller_id' => 'required|exists:users,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10|max:1000',
        ]);

        // Check if order belongs to reviewer
        $order = Order::findOrFail($validated['order_id']);
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized to review this order'], 403);
        }

        // Check if order is completed
        if ($order->status !== 'completed') {
            return response()->json(['message' => 'Can only review completed orders'], 400);
        }

        // Check if review already exists
        $existingReview = Review::where('order_id', $validated['order_id'])
            ->where('reviewer_id', $request->user()->id)
            ->first();

        if ($existingReview) {
            return response()->json(['message' => 'You have already reviewed this order'], 400);
        }

        $review = Review::create([
            'order_id' => $validated['order_id'],
            'seller_id' => $validated['seller_id'],
            'reviewer_id' => $request->user()->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'],
        ]);

        return response()->json($review->load('reviewer'), 201);
    }

    /**
     * Update a review
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:10|max:1000',
        ]);

        $review = Review::findOrFail($id);

        // Check if user owns this review
        if ($review->reviewer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $review->update($validated);

        return response()->json($review->load('reviewer'));
    }

    /**
     * Delete a review
     */
    public function destroy(Request $request, $id)
    {
        $review = Review::findOrFail($id);

        // Check if user owns this review
        if ($review->reviewer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $review->delete();

        return response()->json(['message' => 'Review deleted successfully']);
    }

    /**
     * Mark review as helpful
     */
    public function markHelpful(Request $request, $id)
    {
        $review = Review::findOrFail($id);
        $userId = $request->user()->id;

        // Toggle helpful status
        if ($review->helpfulVoters()->where('user_id', $userId)->exists()) {
            $review->helpfulVoters()->detach($userId);
            $wasHelpful = false;
        } else {
            $review->helpfulVoters()->attach($userId);
            $wasHelpful = true;
        }

        return response()->json([
            'helpful_count' => $review->helpfulVoters()->count(),
            'user_found_helpful' => $wasHelpful,
        ]);
    }

    /**
     * Report a review
     */
    public function report(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $review = Review::findOrFail($id);

        // Cannot report own review
        if ($review->reviewer_id === $request->user()->id) {
            return response()->json(['message' => 'Cannot report your own review'], 400);
        }

        DB::table('review_reports')->insert([
            'review_id' => $review->id,
            'reporter_id' => $request->user()->id,
            'reason' => $validated['reason'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Review reported successfully']);
    }
}
