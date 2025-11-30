<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

Route::get('/', function () {
    return view('welcome');
});

// Enhanced health check endpoint
Route::get('/health', [HealthCheckController::class, 'check']);

// Persona webhook (public, bypass CSRF)
Route::post('/webhooks/persona', [WebhookController::class, 'persona'])
    ->withoutMiddleware([VerifyCsrfToken::class]);

// Paylink payment callback (public route - no auth required)
Route::get('/payments/paylink/callback', [PaymentController::class, 'callback'])
    ->name('payments.paylink.callback');

// HyperPay payment callback (public route - no auth required)
Route::get('/payments/hyperpay/callback', [PaymentController::class, 'hyperPayCallback'])
    ->name('payments.hyperpay.callback');
