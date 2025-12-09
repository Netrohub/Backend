<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Image;

class ImageController extends Controller
{
    /**
     * Upload images to Cloudflare Images
     * Accepts multiple images and returns array of URLs
     */
    public function upload(Request $request)
    {
        // Validate uploaded files
        $validated = $request->validate([
            'images' => 'required|array|max:12', // Up to 12 images (8 listing + 4 bills)
            'images.*' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // Max 5MB per image
        ]);

        $uploadedImages = [];
        $files = $request->file('images');
        
        if (!$files || !is_array($files)) {
            return response()->json([
                'message' => 'No files uploaded',
            ], 400);
        }

        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');
        $accountHash = config('services.cloudflare.account_hash');

        // Validate Cloudflare configuration
        if (!$accountId || !$apiToken || !$accountHash) {
            Log::error('Cloudflare Images configuration missing', [
                'has_account_id' => !empty($accountId),
                'has_api_token' => !empty($apiToken),
                'has_account_hash' => !empty($accountHash),
                'account_id_length' => $accountId ? strlen($accountId) : 0,
                'api_token_length' => $apiToken ? strlen($apiToken) : 0,
                'account_hash_length' => $accountHash ? strlen($accountHash) : 0,
            ]);
            
            return response()->json([
                'message' => 'Image service configuration error. Please ensure CLOUDFLARE_ACCOUNT_ID, CLOUDFLARE_API_TOKEN, and CLOUDFLARE_ACCOUNT_HASH are set in environment variables.',
                'error_code' => 'CLOUDFLARE_CONFIG_MISSING',
                'debug' => [
                    'has_account_id' => !empty($accountId),
                    'has_api_token' => !empty($apiToken),
                    'has_account_hash' => !empty($accountHash),
                ],
            ], 500);
        }

        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            // SECURITY: Validate file magic bytes to prevent MIME type spoofing
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!\App\Helpers\SecurityHelper::validateFileSignature($file, $allowedMimeTypes)) {
                Log::warning('File upload rejected: Invalid magic bytes', [
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'user_id' => $request->user()?->id,
                ]);
                continue; // Skip this file
            }

            try {
                // Log upload attempt for debugging
                Log::info('Attempting Cloudflare Images upload', [
                    'filename' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'account_id_length' => strlen($accountId),
                    'api_token_length' => strlen($apiToken),
                    'url' => "https://api.cloudflare.com/client/v4/accounts/{$accountId}/images/v1",
                ]);

                // Upload to Cloudflare Images API
                $response = Http::withToken($apiToken)
                    ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
                    ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/images/v1");

                if (!$response->successful()) {
                    $errorBody = $response->json();
                    
                    Log::error('Cloudflare Images upload failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'filename' => $file->getClientOriginalName(),
                        'account_id_length' => strlen($accountId),
                        'api_token_prefix' => substr($apiToken, 0, 10) . '...',
                        'error_code' => $errorBody['errors'][0]['code'] ?? 'unknown',
                        'error_message' => $errorBody['errors'][0]['message'] ?? 'unknown',
                    ]);
                    
                    continue; // Skip this image and continue with others
                }

                $result = $response->json('result');
                $imageId = $result['id'];
                
                // Build delivery URL
                $deliveryUrl = "https://imagedelivery.net/{$accountHash}/{$imageId}/public";
                
                // Extract metadata
                $meta = [
                    'filename' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
                
                // Add variants if available in response
                if (isset($result['variants']) && is_array($result['variants'])) {
                    $meta['variants'] = $result['variants'];
                }

                // Save to database
                $image = Image::create([
                    'user_id' => $request->user()?->id,
                    'image_id' => $imageId,
                    'filename' => $file->getClientOriginalName(),
                    'url' => $deliveryUrl,
                    'meta' => $meta,
                ]);

                $uploadedImages[] = [
                    'id' => $image->id,
                    'image_id' => $imageId,
                    'url' => $deliveryUrl,
                    'thumbnail_url' => "https://imagedelivery.net/{$accountHash}/{$imageId}/thumbnail",
                    'medium_url' => "https://imagedelivery.net/{$accountHash}/{$imageId}/medium",
                ];

                Log::info('Image uploaded to Cloudflare', [
                    'image_id' => $imageId,
                    'filename' => $file->getClientOriginalName(),
                    'user_id' => $request->user()?->id,
                ]);

            } catch (\Exception $e) {
                Log::error('Image upload exception', [
                    'error' => $e->getMessage(),
                    'filename' => $file->getClientOriginalName(),
                ]);
                
                continue; // Skip this image and continue
            }
        }

        if (empty($uploadedImages)) {
            Log::error('Image upload: All images failed', [
                'total_files' => count($files),
                'user_id' => $request->user()?->id,
            ]);
            
            return response()->json([
                'message' => 'No images were uploaded successfully. Please check: 1) File sizes are under 5MB each, 2) Files are valid images (JPEG, PNG, GIF, WebP), 3) Cloudflare Images service is configured correctly.',
                'error_code' => 'UPLOAD_FAILED',
                'total_files' => count($files),
            ], 500);
        }

        // Return URLs array for backward compatibility with existing frontend code
        return response()->json([
            'urls' => array_column($uploadedImages, 'url'),
            'images' => $uploadedImages, // Full image data
            'count' => count($uploadedImages),
        ]);
    }

    /**
     * Delete an image from Cloudflare Images
     */
    public function destroy(Request $request, $id)
    {
        $image = Image::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');

        try {
            // Delete from Cloudflare
            $response = Http::withToken($apiToken)
                ->delete("https://api.cloudflare.com/client/v4/accounts/{$accountId}/images/v1/{$image->image_id}");

            if (!$response->successful()) {
                Log::warning('Cloudflare Images delete failed (continuing anyway)', [
                    'status' => $response->status(),
                    'image_id' => $image->image_id,
                    'response' => $response->body(),
                ]);
                // Continue to delete from DB even if Cloudflare delete fails
            }

            // Delete from database
            $image->delete();

            Log::info('Image deleted', [
                'image_id' => $image->image_id,
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Image deleted successfully',
                'deleted' => true,
            ]);

        } catch (\Exception $e) {
            Log::error('Image deletion error', [
                'error' => $e->getMessage(),
                'image_id' => $image->image_id,
            ]);
            
            return response()->json([
                'message' => 'Failed to delete image',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all images for the authenticated user
     */
    public function index(Request $request)
    {
        $images = Image::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($images);
    }

    /**
     * Verify Cloudflare Images configuration (diagnostic endpoint)
     */
    public function verifyConfig(Request $request)
    {
        $accountId = config('services.cloudflare.account_id');
        $apiToken = config('services.cloudflare.api_token');
        $accountHash = config('services.cloudflare.account_hash');

        $config = [
            'has_account_id' => !empty($accountId),
            'has_api_token' => !empty($apiToken),
            'has_account_hash' => !empty($accountHash),
            'account_id_length' => $accountId ? strlen($accountId) : 0,
            'api_token_prefix' => $apiToken ? substr($apiToken, 0, 10) . '...' : null,
            'account_hash_length' => $accountHash ? strlen($accountHash) : 0,
        ];

        $allConfigured = $config['has_account_id'] && 
                         $config['has_api_token'] && 
                         $config['has_account_hash'];

        if (!$allConfigured) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cloudflare Images is not fully configured',
                'config' => $config,
                'required_env_vars' => [
                    'CLOUDFLARE_ACCOUNT_ID' => $config['has_account_id'],
                    'CLOUDFLARE_API_TOKEN' => $config['has_api_token'],
                    'CLOUDFLARE_ACCOUNT_HASH' => $config['has_account_hash'],
                ],
            ], 500);
        }

        // Test API connection
        try {
            $response = Http::withToken($apiToken)
                ->get("https://api.cloudflare.com/client/v4/accounts/{$accountId}/images/v2/stats");

            if (!$response->successful()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cloudflare API authentication failed',
                    'cloudflare_error' => $response->json(),
                    'config' => $config,
                ], 500);
            }

            $stats = $response->json('result');

            return response()->json([
                'status' => 'success',
                'message' => 'Cloudflare Images is properly configured',
                'config' => $config,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to connect to Cloudflare API',
                'error' => $e->getMessage(),
                'config' => $config,
            ], 500);
        }
    }
}
