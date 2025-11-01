<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    /**
     * Upload images and return their URLs
     * Accepts multiple images and returns array of URLs
     */
    public function upload(Request $request)
    {
        // Validate uploaded files
        // Laravel expects 'images' as an array of files
        $validated = $request->validate([
            'images' => 'required|array|max:11', // Up to 11 images (8 listing + 3 bills)
            'images.*' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // Max 5MB per image
        ]);

        $uploadedUrls = [];

        // Get all uploaded files from the 'images' array
        $files = $request->file('images');
        
        if (!$files || !is_array($files)) {
            return response()->json([
                'message' => 'No files uploaded',
            ], 400);
        }

        foreach ($files as $image) {
            if (!$image || !$image->isValid()) {
                continue;
            }

            // Generate unique filename
            $filename = Str::uuid() . '.' . $image->getClientOriginalExtension();
            
            // Store in public disk
            $path = $image->storeAs('listings', $filename, 'public');
            
            // Get public URL
            $url = Storage::disk('public')->url($path);
            
            $uploadedUrls[] = $url;
        }

        if (empty($uploadedUrls)) {
            return response()->json([
                'message' => 'No valid images were uploaded',
            ], 400);
        }

        return response()->json([
            'urls' => $uploadedUrls,
            'count' => count($uploadedUrls),
        ]);
    }
}

