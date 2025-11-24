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
use App\Http\Controllers\SiteSettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public webhooks and versioned API routes live here.
|
*/

// API Version 1 routes
Route::prefix('v1')->group(function () {
    // KYC routes
    Route::middleware(['auth:sanctum', 'throttle:10,60'])->post('/kyc/complete', [KycController::class, 'complete']);
    // Public routes with rate limiting for authentication endpoints
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    });
    
    // Email verification (requires signed URL, rate limited to prevent abuse)
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:10,60']) // 10 per hour
        ->name('verification.verify');
    Route::get('/leaderboard', [LeaderboardController::class, 'index']);
    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{id}', [MemberController::class, 'show']); // Supports both ID and username
    
    // Site Settings (public read for terms & privacy)
    Route::get('/site-settings/{key}', [SiteSettingController::class, 'show']);
    
    // Public maintenance status check
    Route::get('/public/maintenance-status', [SettingsController::class, 'maintenanceStatus']);
    
    // Listings (public - anyone can browse, but creating/updating requires auth)
    Route::get('/listings', [ListingController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/listings/{id}', [ListingController::class, 'show'])->middleware('throttle:30,1');
    
    // Public reviews
    Route::get('/reviews/seller/{sellerId}', [ReviewController::class, 'index']);
    Route::get('/reviews/seller/{sellerId}/stats', [ReviewController::class, 'stats']);

    // Public suggestions and platform reviews
    Route::get('/suggestions', [SuggestionController::class, 'index']);
    Route::get('/platform/stats', [SuggestionController::class, 'platformStats']);

    // Webhooks (no auth required, but rate limited by IP)
    // Rate limit: 60 requests per minute per IP to prevent DoS attacks
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/webhook/persona', [WebhookController::class, 'persona']);
        Route::post('/webhook/paylink', [WebhookController::class, 'paylink']);
        // Tap transfer webhook (still used for withdrawals)
        Route::post('/webhook/tap/transfer', [WebhookController::class, 'tapTransfer']);
    });

    // TikTok OAuth Callback (no auth required - receives code from TikTok)
    Route::get('/tiktok/callback', [\App\Http\Controllers\TikTokController::class, 'callback']);

    // Discord OAuth2 routes (no auth required for redirect/callback)
    Route::get('/auth/discord/redirect', [\App\Http\Controllers\DiscordAuthController::class, 'redirect'])
        ->name('discord.redirect');
    Route::get('/auth/discord/callback', [\App\Http\Controllers\DiscordAuthController::class, 'callback'])
        ->name('discord.callback');

    // Protected routes
        Route::middleware('auth:sanctum')->group(function () {
        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::get('/user/stats', [AuthController::class, 'stats']);
        Route::get('/user/activity', [AuthController::class, 'activity']);
        Route::put('/user/profile', [AuthController::class, 'updateProfile']);
        Route::post('/user/avatar', [AuthController::class, 'updateAvatar'])->middleware('throttle:30,60');
        
        // Password change with rate limiting (5 attempts per 60 minutes)
        Route::middleware('throttle:5,60')->group(function () {
            Route::put('/user/password', [AuthController::class, 'updatePassword']);
        });
        
        // TikTok OAuth and Verification
        Route::prefix('tiktok')->group(function () {
            Route::get('/authorize', [\App\Http\Controllers\TikTokController::class, 'authorize']);
            Route::get('/profile', [\App\Http\Controllers\TikTokController::class, 'getProfile']);
            Route::post('/verify-bio', [\App\Http\Controllers\TikTokController::class, 'verifyBio'])->middleware('throttle:10,1');
            Route::post('/disconnect', [\App\Http\Controllers\TikTokController::class, 'disconnect']);
        });
        
        // Discord OAuth (connect/disconnect)
        Route::prefix('discord')->group(function () {
            Route::post('/disconnect', [\App\Http\Controllers\DiscordAuthController::class, 'disconnect']);
        });
        
        Route::prefix('kyc')->middleware('throttle:30,1')->group(function () {
            Route::get('/status', [KycController::class, 'status']);
        });

        // Email verification (strict rate limit to prevent email spam)
        Route::post('/email/resend', [AuthController::class, 'sendVerificationEmail'])
            ->middleware('throttle:3,60') // 3 per hour (very strict - prevent spam)
            ->name('verification.send');

        // My listings (user's own listings only - data isolation)
        Route::get('/my-listings', [ListingController::class, 'myListings'])->middleware('throttle:60,1');
        
        // Listing credentials (only accessible after purchase)
        Route::get('/listings/{id}/credentials', [ListingController::class, 'getCredentials'])->middleware('throttle:30,1');

        // Orders (require email verification for creation)
        // Increased limits: 30 orders per hour (reasonable for legitimate users)
        // SECURITY: Use account-based rate limiting + origin validation for financial operations
        Route::middleware(['verified', 'validateOrigin'])->group(function () {
            Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle.user:60,60');
            Route::post('/payments/create', [PaymentController::class, 'create'])->middleware('throttle.user:60,60');
            Route::post('/checkout', [PaymentController::class, 'create'])->middleware('throttle.user:60,60'); // Alias for compatibility
        });
        
        Route::get('/orders', [OrderController::class, 'index'])->middleware('throttle:120,1'); // Increased to 120/min
        Route::get('/orders/{id}', [OrderController::class, 'show'])->middleware('throttle:120,1'); // Increased to 120/min
        Route::put('/orders/{id}', [OrderController::class, 'update'])->middleware('throttle:120,60');
        
        // Order actions (confirm, cancel)
        // SECURITY: Use account-based rate limiting + origin validation for order operations
        Route::middleware('validateOrigin')->group(function () {
            Route::post('/orders/{id}/confirm', [OrderController::class, 'confirm'])->middleware('throttle.user:60,60');
            Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->middleware('throttle.user:60,60');
        });

        // Disputes with rate limiting to prevent spam and abuse
        Route::middleware('throttle:30,1')->group(function () {
            Route::get('/disputes', [DisputeController::class, 'index']);
            Route::get('/disputes/{dispute}', [DisputeController::class, 'show']);
        });
        
        Route::middleware(['throttle:30,60', \App\Http\Middleware\RequireDiscordForDisputes::class])->group(function () {
            Route::post('/disputes', [DisputeController::class, 'store']);
            Route::post('/disputes/{dispute}/cancel', [DisputeController::class, 'cancel']);
        });
        
        // Admin-only dispute management (still rate limited for safety)
        Route::middleware(['admin', 'throttle:20,1'])->group(function () {
            Route::put('/disputes/{dispute}', [DisputeController::class, 'update']);
            Route::delete('/disputes/{dispute}', [DisputeController::class, 'destroy']);
        });

        // Reviews (protected - must be authenticated)
        // Rate limiting to prevent spam and abuse
        Route::middleware('throttle:60,60')->group(function () {
            Route::post('/reviews', [ReviewController::class, 'store']);
            Route::put('/reviews/{id}', [ReviewController::class, 'update']);
        });
        
        Route::middleware('throttle:120,60')->group(function () {
            Route::post('/reviews/{id}/helpful', [ReviewController::class, 'markHelpful']);
        });
        
        Route::middleware('throttle:30,60')->group(function () {
            Route::post('/reviews/{id}/report', [ReviewController::class, 'report']);
            Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
        });

        // Wallet (withdrawals require email verification)
        Route::get('/wallet', [WalletController::class, 'index']);
        Route::get('/wallet/withdrawals', [WalletController::class, 'withdrawalHistory']);
        Route::get('/wallet/fee-info', [WalletController::class, 'withdrawalFeeInfo']);
        
        // Withdrawal endpoint with account-based rate limiting + origin validation
        // SECURITY: Financial operations require strict validation
        Route::middleware(['verified', 'validateOrigin', 'throttle.user:10,60'])->group(function () {
            Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);
        });

        // Notifications with rate limiting to prevent spam
        // Increased to 240/min (4 req/sec) to support bell polling
        Route::middleware('throttle:240,1')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        });
        
        Route::middleware('throttle:240,1')->group(function () {
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
            Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        });
        
        Route::middleware('throttle:60,1')->group(function () {
            Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
            Route::delete('/notifications/read/all', [NotificationController::class, 'deleteAllRead']);
        });

        // Settings (public read access for certain settings)
        Route::get('/settings/{key}', [SettingsController::class, 'show']);

        // Listing and image management (only verified users can upload or create)
        Route::middleware(['kycVerified', 'throttle:30,1', \App\Http\Middleware\RequireDiscordForSellers::class])->group(function () {
            Route::post('/listings', [ListingController::class, 'store']);
            Route::put('/listings/{id}', [ListingController::class, 'update']);
            Route::delete('/listings/{id}', [ListingController::class, 'destroy']);

            Route::post('/images/upload', [ImageController::class, 'upload']);
            Route::get('/images', [ImageController::class, 'index']);
            Route::delete('/images/{id}', [ImageController::class, 'destroy']);
            Route::get('/images/verify-config', [ImageController::class, 'verifyConfig']);
        });

        // Payment callback (handled by frontend, but route exists for reference)
        // Frontend should handle: /orders/{id}/payment/callback
        // This route would be in web.php if backend needs to handle callback

        // Suggestions and Platform Reviews
        Route::post('/suggestions', [SuggestionController::class, 'store']);
        Route::post('/suggestions/{id}/vote', [SuggestionController::class, 'vote']);
        Route::post('/platform/review', [SuggestionController::class, 'submitPlatformReview']);
        Route::get('/platform/review/user', [SuggestionController::class, 'getUserPlatformReview']);

        // Admin routes (require admin role with rate limiting)
        Route::prefix('admin')->middleware(['admin', 'throttle:300,1'])->group(function () {
            // Dashboard (read-only, can be polled)
            Route::get('/stats', [AdminController::class, 'stats']);
            
            // Site Settings Management
            Route::get('/site-settings', [SiteSettingController::class, 'index']);
            Route::put('/site-settings/{key}', [SiteSettingController::class, 'update']);
            Route::get('/activity', [AdminController::class, 'activity']);
            
            // Read operations (generous limits for browsing)
                    Route::get('/users', [AdminController::class, 'users']);
                    Route::get('/listings', [AdminController::class, 'listings']);
                    Route::get('/orders', [AdminController::class, 'orders']);
                    Route::get('/disputes', [AdminController::class, 'disputes']);
                    Route::get('/reviews', [AdminController::class, 'reviews']);
                    Route::get('/financial', [AdminController::class, 'financial']);
                    Route::get('/settings', [SettingsController::class, 'index']);
                    Route::get('/kyc', [AdminController::class, 'kyc']);
            
            // Write operations (rate limiting for safety)
            Route::middleware('throttle:120,1')->group(function () {
                // User management
                Route::put('/users/{id}', [AdminController::class, 'updateUser']);
                Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
                
                // Listing management
                Route::put('/listings/{id}/status', [AdminController::class, 'updateListingStatus']);
                Route::delete('/listings/{id}', [AdminController::class, 'deleteListing']);
                
                // Order management
                Route::post('/orders/{id}/cancel', [AdminController::class, 'cancelOrder']);
                
                // Notifications
                Route::post('/notifications', [NotificationController::class, 'store']);
                Route::get('/notifications/history', [NotificationController::class, 'adminHistory']);
                
                // Settings
                Route::post('/settings', [SettingsController::class, 'store']);
                Route::put('/settings/{key}', [SettingsController::class, 'update']);
                Route::post('/settings/bulk', [SettingsController::class, 'bulkUpdate']);
                Route::delete('/settings/{key}', [SettingsController::class, 'destroy']);
                
                // Suggestion management
                Route::put('/suggestions/{id}', [AdminController::class, 'updateSuggestion']);
                Route::delete('/suggestions/{id}', [AdminController::class, 'deleteSuggestion']);
                
                // Withdrawal management (manual approval)
                Route::get('/withdrawals', [AdminController::class, 'withdrawals']);
                Route::post('/withdrawals/{id}/approve', [AdminController::class, 'approveWithdrawal']);
                Route::post('/withdrawals/{id}/reject', [AdminController::class, 'rejectWithdrawal']);
            });
        });
    });
});

// Legacy routes removed - all clients should use /api/v1/ prefix
