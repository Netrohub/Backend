<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupAbandonedPaymentIntents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     * Delete payment_intent orders older than 24 hours (abandoned, never paid)
     */
    public function handle(): void
    {
        $cutoffTime = now()->subHours(24);
        
        $deletedCount = Order::where('status', 'payment_intent')
            ->where('created_at', '<', $cutoffTime)
            ->delete();
        
        if ($deletedCount > 0) {
            Log::info('Cleaned up abandoned payment intents', [
                'deleted_count' => $deletedCount,
                'cutoff_time' => $cutoffTime->toIso8601String(),
            ]);
        }
    }
}
