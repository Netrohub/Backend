<?php

namespace App\Http\Controllers;

use App\Models\Suggestion;
use App\Models\SuggestionVote;
use App\Models\PlatformReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\MessageHelper;
use App\Helpers\PaginationHelper;

class SuggestionController extends Controller
{
    // Get all suggestions
    public function index(Request $request)
    {
        $query = Suggestion::with('user')
            ->withCount('votes');

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $suggestions = PaginationHelper::paginate(
            $query->orderBy('created_at', 'desc'),
            $request
        );

        // Add user's vote for each suggestion if authenticated
        if ($request->user()) {
            $suggestions['data'] = array_map(function ($suggestion) use ($request) {
                $vote = SuggestionVote::where('suggestion_id', $suggestion->id)
                    ->where('user_id', $request->user()->id)
                    ->first();
                
                $suggestion->user_vote = $vote ? $vote->vote_type : null;
                return $suggestion;
            }, $suggestions['data']);
        }

        return response()->json($suggestions);
    }

    // Create new suggestion
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
        ]);

        $suggestion = Suggestion::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => 'pending',
        ]);

        return response()->json($suggestion->load('user'), 201);
    }

    // Vote on suggestion
    public function vote(Request $request, $id)
    {
        $validated = $request->validate([
            'vote_type' => 'required|in:up,down',
        ]);

        $suggestion = Suggestion::findOrFail($id);

        DB::transaction(function () use ($suggestion, $request, $validated) {
            // Check if user already voted
            $existingVote = SuggestionVote::where('suggestion_id', $suggestion->id)
                ->where('user_id', $request->user()->id)
                ->first();

            if ($existingVote) {
                if ($existingVote->vote_type === $validated['vote_type']) {
                    // Same vote - remove it (toggle off)
                    $existingVote->delete();
                    
                    if ($validated['vote_type'] === 'up') {
                        $suggestion->decrement('upvotes');
                    } else {
                        $suggestion->decrement('downvotes');
                    }
                } else {
                    // Different vote - switch it
                    if ($validated['vote_type'] === 'up') {
                        $suggestion->decrement('downvotes');
                        $suggestion->increment('upvotes');
                    } else {
                        $suggestion->decrement('upvotes');
                        $suggestion->increment('downvotes');
                    }
                    
                    $existingVote->vote_type = $validated['vote_type'];
                    $existingVote->save();
                }
            } else {
                // New vote
                SuggestionVote::create([
                    'suggestion_id' => $suggestion->id,
                    'user_id' => $request->user()->id,
                    'vote_type' => $validated['vote_type'],
                ]);

                if ($validated['vote_type'] === 'up') {
                    $suggestion->increment('upvotes');
                } else {
                    $suggestion->increment('downvotes');
                }
            }
        });

        return response()->json($suggestion->fresh());
    }

    // Get platform review stats
    public function platformStats()
    {
        $stats = DB::table('platform_reviews')
            ->selectRaw('
                AVG(rating) as average_rating,
                COUNT(*) as total_reviews,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating_1
            ')
            ->whereNull('deleted_at')
            ->first();

        return response()->json([
            'average_rating' => round($stats->average_rating ?? 0, 1),
            'total_reviews' => $stats->total_reviews ?? 0,
            'rating_distribution' => [
                '5' => $stats->rating_5 ?? 0,
                '4' => $stats->rating_4 ?? 0,
                '3' => $stats->rating_3 ?? 0,
                '2' => $stats->rating_2 ?? 0,
                '1' => $stats->rating_1 ?? 0,
            ],
        ]);
    }

    // Submit platform review
    public function submitPlatformReview(Request $request)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string',
        ]);

        // Check if user already reviewed
        $existingReview = PlatformReview::where('user_id', $request->user()->id)->first();

        if ($existingReview) {
            $existingReview->update($validated);
            $review = $existingReview;
        } else {
            $review = PlatformReview::create([
                'user_id' => $request->user()->id,
                'rating' => $validated['rating'],
                'review' => $validated['review'] ?? null,
            ]);
        }

        return response()->json($review, 201);
    }

    // Get user's platform review
    public function getUserPlatformReview(Request $request)
    {
        $review = PlatformReview::where('user_id', $request->user()->id)->first();
        return response()->json($review);
    }
}
