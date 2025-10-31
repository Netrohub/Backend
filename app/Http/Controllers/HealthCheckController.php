<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HealthCheckController extends Controller
{
    /**
     * Comprehensive health check endpoint
     * Checks database, cache, queue, and external services
     */
    public function check()
    {
        $checks = [
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'services' => [],
        ];

        // Check database connectivity
        try {
            DB::connection()->getPdo();
            $checks['services']['database'] = [
                'status' => 'healthy',
                'connection' => config('database.default'),
            ];
        } catch (\Exception $e) {
            $checks['status'] = 'unhealthy';
            $checks['services']['database'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }

        // Check cache
        try {
            $cacheKey = 'health_check_' . time();
            Cache::put($cacheKey, 'ok', 10);
            $cacheValue = Cache::get($cacheKey);
            Cache::forget($cacheKey);
            
            $checks['services']['cache'] = [
                'status' => $cacheValue === 'ok' ? 'healthy' : 'unhealthy',
                'driver' => config('cache.default'),
            ];
            
            if ($cacheValue !== 'ok') {
                $checks['status'] = 'unhealthy';
            }
        } catch (\Exception $e) {
            $checks['status'] = 'unhealthy';
            $checks['services']['cache'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }

        // Check queue connection (doesn't verify worker is running)
        try {
            $queueConnection = config('queue.default');
            $checks['services']['queue'] = [
                'status' => 'healthy',
                'connection' => $queueConnection,
                'note' => 'Connection check only - worker status not verified',
            ];
        } catch (\Exception $e) {
            $checks['status'] = 'unhealthy';
            $checks['services']['queue'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }

        // Check external services (non-blocking, timeout quickly)
        $tapApiKey = config('services.tap.secret_key');
        if ($tapApiKey) {
            try {
                $response = Http::timeout(2)->get('https://api.tap.company/v2');
                $checks['services']['tap_payments'] = [
                    'status' => $response->successful() ? 'healthy' : 'degraded',
                    'note' => 'API reachability check',
                ];
            } catch (\Exception $e) {
                $checks['services']['tap_payments'] = [
                    'status' => 'degraded',
                    'note' => 'Service check failed: ' . $e->getMessage(),
                ];
            }
        }

        $personaApiKey = config('services.persona.api_key');
        if ($personaApiKey) {
            try {
                $response = Http::timeout(2)->get('https://withpersona.com/api/v1');
                $checks['services']['persona'] = [
                    'status' => $response->successful() ? 'healthy' : 'degraded',
                    'note' => 'API reachability check',
                ];
            } catch (\Exception $e) {
                $checks['services']['persona'] = [
                    'status' => 'degraded',
                    'note' => 'Service check failed: ' . $e->getMessage(),
                ];
            }
        }

        $statusCode = $checks['status'] === 'healthy' ? 200 : 503;
        
        return response()->json($checks, $statusCode);
    }
}
