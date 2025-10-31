<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;

Route::get('/', function () {
    return view('welcome');
});

// Enhanced health check endpoint
Route::get('/health', [HealthCheckController::class, 'check']);
