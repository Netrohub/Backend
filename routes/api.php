<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
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
    Route::get('/leaderboard', [LeaderboardController::class, 'index']);
    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{id}', [MemberController::class, 'show']);
    
    // Listings (public - anyone can browse, but creating/updating requires auth)
    Route::get('/listings', [ListingController::class, 'index']);
    Route::get('/listings/{id}', [ListingController::class, 'show']);

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

        // Listings (require email verification for creating/updating/deleting)
        Route::middleware('verified')->group(function () {
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

        // Wallet (withdrawals require email verification)
        Route::get('/wallet', [WalletController::class, 'index']);
        Route::middleware('verified')->group(function () {
            Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);
        });

        // KYC
        Route::get('/kyc', [KycController::class, 'index']);
        Route::post('/kyc', [KycController::class, 'create']);
        
        // Payment callback (handled by frontend, but route exists for reference)
        // Frontend should handle: /orders/{id}/payment/callback
        // This route would be in web.php if backend needs to handle callback

        // Admin routes (require admin role)
        Route::prefix('admin')->middleware('admin')->group(function () {
            Route::get('/users', [AdminController::class, 'users']);
            Route::put('/users/{id}', [AdminController::class, 'updateUser']);
            Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
            Route::get('/disputes', [AdminController::class, 'disputes']);
            Route::get('/listings', [AdminController::class, 'listings']);
            Route::get('/orders', [AdminController::class, 'orders']);
            Route::get('/kyc', [AdminController::class, 'kyc']);
        });
    });
});

// Legacy routes without version prefix (deprecated, redirect to v1)
// These are kept for backward compatibility but should be removed in future versions
Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('api.leaderboard.legacy');
Route::get('/members', [MemberController::class, 'index'])->name('api.members.legacy');
Route::get('/members/{id}', [MemberController::class, 'show'])->name('api.members.show.legacy');
