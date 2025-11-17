<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use App\Jobs\CleanupAbandonedPaymentIntents;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure API rate limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = config('app.frontend_url', config('app.url'));
            $base = rtrim($frontendUrl, '/');
            $email = urlencode($user->email);
            return "{$base}/reset-password?token={$token}&email={$email}";
        });

        // Schedule cleanup of abandoned payment intents (not real orders) daily
        Schedule::job(new CleanupAbandonedPaymentIntents)->daily();
    }
}
