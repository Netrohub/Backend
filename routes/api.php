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

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/leaderboard', [LeaderboardController::class, 'index']);
Route::get('/members', [MemberController::class, 'index']);
Route::get('/members/{id}', [MemberController::class, 'show']);

// Webhooks (no auth required)
Route::post('/webhook/tap', [WebhookController::class, 'tap']);
Route::post('/webhook/persona', [WebhookController::class, 'persona']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Listings
    Route::apiResource('listings', ListingController::class);

    // Orders
    Route::apiResource('orders', OrderController::class);

    // Disputes
    Route::apiResource('disputes', DisputeController::class);

    // Wallet
    Route::get('/wallet', [WalletController::class, 'index']);
    Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);

    // KYC
    Route::get('/kyc', [KycController::class, 'index']);
    Route::post('/kyc', [KycController::class, 'create']);

    // Payments
    Route::post('/payments/create', [PaymentController::class, 'create']);

    // Admin routes
    Route::prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::get('/disputes', [AdminController::class, 'disputes']);
        Route::get('/listings', [AdminController::class, 'listings']);
        Route::get('/orders', [AdminController::class, 'orders']);
        Route::get('/kyc', [AdminController::class, 'kyc']);
    });
});
