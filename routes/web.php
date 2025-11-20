<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\PaymentController;

Route::get('/', function () {
    return view('welcome');
});

// Enhanced health check endpoint
Route::get('/health', [HealthCheckController::class, 'check']);

// Paylink payment callback (public route - no auth required)
Route::get('/payments/paylink/callback', [PaymentController::class, 'callback'])
    ->name('payments.paylink.callback');
