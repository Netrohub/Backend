<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\MessageHelper;

class ListingController extends Controller
{
    public function index(Request $request)
    {
        $query = Listing::with('user')
            ->where('status', 'active');

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $listings = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($listings);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'category' => 'required|string',
            'images' => 'nullable|array',
            'images.*' => 'string',
        ]);

        $listing = Listing::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'price' => $validated['price'],
            'category' => $validated['category'],
            'images' => $validated['images'] ?? [],
            'status' => 'active',
        ]);

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
            'description' => 'sometimes|string',
            'price' => 'sometimes|numeric|min:0',
            'category' => 'sometimes|string',
            'images' => 'sometimes|array',
            'images.*' => 'string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $listing->update($validated);

        return response()->json($listing->load('user'));
    }

    public function destroy(Request $request, $id)
    {
        $listing = Listing::findOrFail($id);

        if ($listing->user_id !== $request->user()->id) {
            return response()->json(['message' => MessageHelper::ERROR_UNAUTHORIZED], 403);
        }

        $listing->delete();

        return response()->json(['message' => 'Listing deleted successfully.']);
    }
}
