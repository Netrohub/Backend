<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Command;

class UpdateGamingProductsImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'listings:update-gaming-images {image-url : The image URL to add to all products}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all Whiteout Survival gift card products with an image URL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $imageUrl = $this->argument('image-url');

        // Validate URL format
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $this->error('Invalid URL format. Please provide a valid image URL.');
            return 1;
        }

        $this->info("Updating gaming products with image: {$imageUrl}");

        // Find all Whiteout Survival gift card products
        $listings = Listing::where('category', 'wos_accounts')
            ->where('title', 'like', '%Whiteout Survival Gift Card%')
            ->where('title', 'like', '%Century Games Store%')
            ->get();

        if ($listings->isEmpty()) {
            $this->warn('No Whiteout Survival gift card products found.');
            $this->info('Make sure you have created the products first using: php artisan listings:create-gaming-products');
            return 1;
        }

        $updated = 0;
        foreach ($listings as $listing) {
            // Set images array with the provided URL
            $listing->images = [$imageUrl];
            $listing->save();
            $updated++;
            $this->info("✓ Updated: {$listing->title} - \${$listing->price}");
        }

        $this->info("\n✅ Successfully updated {$updated} products with the image!");
        $this->line("All products now have the treasure image.");

        return 0;
    }
}

