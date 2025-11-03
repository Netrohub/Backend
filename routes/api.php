<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SuggestionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
|
| All API routes are versioned under /api/v1/ prefix.
| This allows for future API versions (v2, v3, etc.) without breaking
| existing clients.
|
*/

// API Version 1 routes
Route::prefix('v1')->group(function () {
    // Public routes with rate limiting for authentication endpoints
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });
    
    // Email verification (requires signed URL)
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::get('/leaderboard', [LeaderboardController::class, 'index']);
    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{id}', [MemberController::class, 'show']);
    
    // Listings (public - anyone can browse, but creating/updating requires auth)
    Route::get('/listings', [ListingController::class, 'index']);
    Route::get('/listings/{id}', [ListingController::class, 'show']);
    
    // Public reviews
    Route::get('/reviews/seller/{sellerId}', [ReviewController::class, 'index']);
    Route::get('/reviews/seller/{sellerId}/stats', [ReviewController::class, 'stats']);

    // Public suggestions and platform reviews
    Route::get('/suggestions', [SuggestionController::class, 'index']);
    Route::get('/platform/stats', [SuggestionController::class, 'platformStats']);

    // Webhooks (no auth required, but rate limited by IP)
    // Rate limit: 60 requests per minute per IP to prevent DoS attacks
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/webhook/tap', [WebhookController::class, 'tap']);
        Route::post('/webhook/persona', [WebhookController::class, 'persona']);
        // Transfer webhook route (if Tap sends separate transfer webhooks)
        Route::post('/webhook/tap/transfer', [WebhookController::class, 'tapTransfer']);
    });

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::get('/user/stats', [AuthController::class, 'stats']);
        Route::get('/user/activity', [AuthController::class, 'activity']);
        Route::put('/user/profile', [AuthController::class, 'updateProfile']);
        Route::put('/user/password', [AuthController::class, 'updatePassword']);
        
        // Email verification
        Route::post('/email/resend', [AuthController::class, 'sendVerificationEmail'])->name('verification.send');

        // Images (require KYC verification)
        Route::middleware('kycVerified')->group(function () {
            Route::post('/images/upload', [ImageController::class, 'upload']);
            Route::get('/images', [ImageController::class, 'index']); // Get user's uploaded images
            Route::delete('/images/{id}', [ImageController::class, 'destroy']); // Delete image
            Route::get('/images/verify-config', [ImageController::class, 'verifyConfig']); // Diagnostic endpoint
        });

        // Listings (require KYC verification for creating/updating/deleting)
        Route::middleware('kycVerified')->group(function () {
            Route::post('/listings', [ListingController::class, 'store']);
            Route::put('/listings/{id}', [ListingController::class, 'update']);
            Route::delete('/listings/{id}', [ListingController::class, 'destroy']);
        });

        // Orders (require email verification for creation)
        Route::middleware('verified')->group(function () {
            Route::post('/orders', [OrderController::class, 'store']);
            Route::post('/payments/create', [PaymentController::class, 'create']);
        });
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::put('/orders/{id}', [OrderController::class, 'update']);

        // Disputes
        Route::apiResource('disputes', DisputeController::class);

        // Reviews (protected - must be authenticated)
        Route::post('/reviews', [ReviewController::class, 'store']);
        Route::put('/reviews/{id}', [ReviewController::class, 'update']);
        Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
        Route::post('/reviews/{id}/helpful', [ReviewController::class, 'markHelpful']);
        Route::post('/reviews/{id}/report', [ReviewController::class, 'report']);

        // Wallet (withdrawals require email verification)
        Route::get('/wallet', [WalletController::class, 'index']);
        Route::middleware('verified')->group(function () {
            Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);
        });

        // KYC
        Route::get('/kyc', [KycController::class, 'index']);
        Route::post('/kyc', [KycController::class, 'create']);
        Route::post('/kyc/sync', [KycController::class, 'sync']); // Manual sync from Persona
        Route::get('/kyc/verify-config', [KycController::class, 'verifyConfig']); // Diagnostic endpoint
        
        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/notifications/read/all', [NotificationController::class, 'deleteAllRead']);
        
        // Settings (public read access for certain settings)
        Route::get('/settings/{key}', [SettingsController::class, 'show']);
        
        // Payment callback (handled by frontend, but route exists for reference)
        // Frontend should handle: /orders/{id}/payment/callback
        // This route would be in web.php if backend needs to handle callback

        // Suggestions and Platform Reviews
        Route::post('/suggestions', [SuggestionController::class, 'store']);
        Route::post('/suggestions/{id}/vote', [SuggestionController::class, 'vote']);
        Route::post('/platform/review', [SuggestionController::class, 'submitPlatformReview']);
        Route::get('/platform/review/user', [SuggestionController::class, 'getUserPlatformReview']);

        // Admin routes (require admin role)
        Route::prefix('admin')->middleware('admin')->group(function () {
            // Dashboard
            Route::get('/stats', [AdminController::class, 'stats']);
            Route::get('/activity', [AdminController::class, 'activity']);
            
            // Users
            Route::get('/users', [AdminController::class, 'users']);
            Route::put('/users/{id}', [AdminController::class, 'updateUser']);
            Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
            
            // Listings
            Route::get('/listings', [AdminController::class, 'listings']);
            Route::put('/listings/{id}/status', [AdminController::class, 'updateListingStatus']);
            Route::delete('/listings/{id}', [AdminController::class, 'deleteListing']);
            
            // Orders
            Route::get('/orders', [AdminController::class, 'orders']);
            Route::post('/orders/{id}/cancel', [AdminController::class, 'cancelOrder']);
            
            // Disputes
            Route::get('/disputes', [AdminController::class, 'disputes']);
            
            // KYC
            Route::get('/kyc', [AdminController::class, 'kyc']);
            
            // Admin Notifications (create notifications for users)
            Route::post('/notifications', [NotificationController::class, 'store']);
            
            // Admin Settings
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::post('/settings', [SettingsController::class, 'store']);
            Route::put('/settings/{key}', [SettingsController::class, 'update']);
            Route::post('/settings/bulk', [SettingsController::class, 'bulkUpdate']);
            Route::delete('/settings/{key}', [SettingsController::class, 'destroy']);
        });
    });
});

// Legacy routes without version prefix (deprecated, redirect to v1)
// These are kept for backward compatibility but should be removed in future versions
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('api.leaderboard.legacy');
Route::get('/members', [MemberController::class, 'index'])->name('api.members.legacy');
Route::get('/members/{id}', [MemberController::class, 'show'])->name('api.members.show.legacy');
