<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use App\Constants\ListingCategories;

class ClearListingsCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'listings:clear-cache {--all : Clear all listing caches including category-specific}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all cached listing data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Clearing listings cache...');

        $cleared = 0;

        if ($this->option('all')) {
            // Clear all possible cache keys for listings
            // This includes category-specific caches
            foreach (ListingCategories::all() as $category) {
                $cacheKey = 'listings_' . md5($category . '');
                if (Cache::forget($cacheKey)) {
                    $cleared++;
                }
            }
            
            // Also clear the default (no category) cache
            $defaultCacheKey = 'listings_' . md5('');
            if (Cache::forget($defaultCacheKey)) {
                $cleared++;
            }
            
            $this->info("Cleared {$cleared} category-specific cache entries.");
        } else {
            // Clear all cache keys that start with 'listings_'
            // This is a more aggressive approach that clears everything
            $this->warn('Clearing all cache entries with "listings_" prefix...');
            
            // Note: This requires iterating through all cache keys
            // For database cache, we'll use a different approach
            $driver = config('cache.default');
            
            if ($driver === 'database') {
                // For database cache, delete entries matching the pattern
                try {
                    $deleted = \DB::table('cache')
                        ->where('key', 'like', 'listings_%')
                        ->delete();
                    $cleared = $deleted;
                    $this->info("Cleared {$cleared} cache entries from database.");
                } catch (\Exception $e) {
                    $this->error("Error clearing database cache: " . $e->getMessage());
                    $this->info("Trying alternative method...");
                    
                    // Fallback: Clear known cache keys
                    foreach (ListingCategories::all() as $category) {
                        $cacheKey = 'listings_' . md5($category . '');
                        Cache::forget($cacheKey);
                        $cleared++;
                    }
                    $defaultCacheKey = 'listings_' . md5('');
                    Cache::forget($defaultCacheKey);
                    $cleared++;
                    
                    $this->info("Cleared {$cleared} cache entries using fallback method.");
                }
            } else {
                // For file/redis cache, try to clear all listing-related caches
                // First try to clear via database if cache table exists
                try {
                    if (\Schema::hasTable('cache')) {
                        $deleted = \DB::table('cache')
                            ->where('key', 'like', 'listings_%')
                            ->delete();
                        $cleared = $deleted;
                        $this->info("Cleared {$cleared} cache entries from database.");
                    } else {
                        // Fallback: Clear known cache keys
                        foreach (ListingCategories::all() as $category) {
                            $cacheKey = 'listings_' . md5($category . '');
                            Cache::forget($cacheKey);
                            $cleared++;
                        }
                        $defaultCacheKey = 'listings_' . md5('');
                        Cache::forget($defaultCacheKey);
                        $cleared++;
                        $this->info("Cleared {$cleared} cache entries.");
                    }
                } catch (\Exception $e) {
                    // Final fallback: Clear known cache keys
                    foreach (ListingCategories::all() as $category) {
                        $cacheKey = 'listings_' . md5($category . '');
                        Cache::forget($cacheKey);
                        $cleared++;
                    }
                    $defaultCacheKey = 'listings_' . md5('');
                    Cache::forget($defaultCacheKey);
                    $cleared++;
                    $this->info("Cleared {$cleared} cache entries using fallback method.");
                }
            }
        }

        $this->info('✅ Listings cache cleared successfully!');
        $this->line('The website should now show updated listing data.');
        
        return 0;
    }
}

