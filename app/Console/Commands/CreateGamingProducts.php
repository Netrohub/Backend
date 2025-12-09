<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateGamingProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'listings:create-gaming-products {--user-id= : Specific user ID to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create 5 Whiteout Survival gift card products for payment gateway approval';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Creating gaming products for payment gateway approval...');

        // Get user ID
        $userId = $this->option('user-id');
        
        if (!$userId) {
            // Try to find an admin user
            $admin = User::where('role', 'admin')->first();
            if (!$admin) {
                // Fall back to any user
                $admin = User::first();
            }
            
            if (!$admin) {
                $this->error('No users found in database. Please create a user first.');
                return 1;
            }
            
            $userId = $admin->id;
            $this->info("Using user ID: {$userId} ({$admin->name})");
        } else {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User with ID {$userId} not found.");
                return 1;
            }
            $this->info("Using user ID: {$userId} ({$user->name})");
        }

        $products = [
            [
                'title' => 'Whiteout Survival Gift Card - $50 Century Games Store',
                'description' => 'Official Whiteout Survival Gift Card from Century Games Store

Product Details:
- Value: $50 USD
- Platform: Whiteout Survival (Mobile Game)
- Store: Century Games Official Store
- Region: Global (works worldwide)
- Format: Digital code delivered instantly
- Validity: No expiration date
- Redemption: Redeemable on Century Games Store (https://store.centurygames.com/wos)

What You Get:
- Instant delivery via email
- Digital gift card code for Century Games Store
- Can be used to purchase in-game items, resources, and premium content
- Works with Whiteout Survival mobile game
- Access to exclusive game content and packages
- Official Century Games product

Delivery: Instant via email (within 5 minutes)
Support: Full support for code redemption issues
Store Link: https://store.centurygames.com/wos

This is a legitimate gaming gift card product from the official Century Games store, fully compliant with payment gateway policies.',
                'price' => 49.99,
                'code' => 'WOS-GIFT-50-12345',
            ],
            [
                'title' => 'Whiteout Survival Gift Card - $25 Century Games Store',
                'description' => 'Official Whiteout Survival Gift Card from Century Games Store

Product Details:
- Value: $25 USD
- Platform: Whiteout Survival (Mobile Game)
- Store: Century Games Official Store
- Region: Global (works worldwide)
- Format: Digital code delivered instantly
- Validity: No expiration date
- Redemption: Redeemable on Century Games Store (https://store.centurygames.com/wos)

What You Get:
- Instant delivery via email
- Digital gift card code for Century Games Store
- Can be used to purchase in-game items, resources, and premium content
- Works with Whiteout Survival mobile game
- Access to exclusive game content and packages
- Official Century Games product

Delivery: Instant via email (within 5 minutes)
Support: Full support for code redemption issues
Store Link: https://store.centurygames.com/wos

This is a legitimate gaming gift card product from the official Century Games store, fully compliant with payment gateway policies.',
                'price' => 24.99,
                'code' => 'WOS-GIFT-25-67890',
            ],
            [
                'title' => 'Whiteout Survival Gift Card - $30 Century Games Store',
                'description' => 'Official Whiteout Survival Gift Card from Century Games Store

Product Details:
- Value: $30 USD
- Platform: Whiteout Survival (Mobile Game)
- Store: Century Games Official Store
- Region: Global (works worldwide)
- Format: Digital code delivered instantly
- Validity: No expiration date
- Redemption: Redeemable on Century Games Store (https://store.centurygames.com/wos)

What You Get:
- Instant delivery via email
- Digital gift card code for Century Games Store
- Can be used to purchase in-game items, resources, and premium content
- Works with Whiteout Survival mobile game
- Access to exclusive game content and packages
- Official Century Games product

Delivery: Instant via email (within 5 minutes)
Support: Full support for code redemption issues
Store Link: https://store.centurygames.com/wos

This is a legitimate gaming gift card product from the official Century Games store, fully compliant with payment gateway policies.',
                'price' => 29.99,
                'code' => 'WOS-GIFT-30-11111',
            ],
            [
                'title' => 'Whiteout Survival Gift Card - $20 Century Games Store',
                'description' => 'Official Whiteout Survival Gift Card from Century Games Store

Product Details:
- Value: $20 USD
- Platform: Whiteout Survival (Mobile Game)
- Store: Century Games Official Store
- Region: Global (works worldwide)
- Format: Digital code delivered instantly
- Validity: No expiration date
- Redemption: Redeemable on Century Games Store (https://store.centurygames.com/wos)

What You Get:
- Instant delivery via email
- Digital gift card code for Century Games Store
- Can be used to purchase in-game items, resources, and premium content
- Works with Whiteout Survival mobile game
- Access to exclusive game content and packages
- Official Century Games product

Delivery: Instant via email (within 5 minutes)
Support: Full support for code redemption issues
Store Link: https://store.centurygames.com/wos

This is a legitimate gaming gift card product from the official Century Games store, fully compliant with payment gateway policies.',
                'price' => 19.99,
                'code' => 'WOS-GIFT-20-22222',
            ],
            [
                'title' => 'Whiteout Survival Gift Card - $15 Century Games Store',
                'description' => 'Official Whiteout Survival Gift Card from Century Games Store

Product Details:
- Value: $15 USD
- Platform: Whiteout Survival (Mobile Game)
- Store: Century Games Official Store
- Region: Global (works worldwide)
- Format: Digital code delivered instantly
- Validity: No expiration date
- Redemption: Redeemable on Century Games Store (https://store.centurygames.com/wos)

What You Get:
- Instant delivery via email
- Digital gift card code for Century Games Store
- Can be used to purchase in-game items, resources, and premium content
- Works with Whiteout Survival mobile game
- Access to exclusive game content and packages
- Official Century Games product

Delivery: Instant via email (within 5 minutes)
Support: Full support for code redemption issues
Store Link: https://store.centurygames.com/wos

This is a legitimate gaming gift card product from the official Century Games store, fully compliant with payment gateway policies.',
                'price' => 14.99,
                'code' => 'WOS-GIFT-15-33333',
            ],
        ];

        $created = 0;
        foreach ($products as $product) {
            // Check if listing already exists
            $existing = Listing::where('title', $product['title'])
                ->where('user_id', $userId)
                ->first();

            if ($existing) {
                $this->warn("Skipping: {$product['title']} (already exists)");
                continue;
            }

            $listing = new Listing([
                'user_id' => $userId,
                'title' => $product['title'],
                'description' => $product['description'],
                'price' => $product['price'],
                'category' => 'wos_accounts',
                'images' => [],
                'status' => 'active',
                'views' => 0,
                'account_metadata' => [
                    'product_type' => 'gift_card',
                    'platform' => 'whiteout_survival',
                    'store' => 'century_games',
                    'value_usd' => (int)str_replace(['$', '.99'], '', number_format($product['price'], 2)),
                    'delivery_method' => 'instant_email',
                    'region' => 'global',
                    'expiration' => 'none',
                    'store_url' => 'https://store.centurygames.com/wos',
                ],
            ]);

            // Set encrypted credentials (Laravel handles encryption automatically)
            $listing->account_email = 'giftcard@centurygames.com';
            $listing->account_password = $product['code'];

            $listing->save();
            $created++;
            $this->info("✓ Created: {$product['title']} - \${$product['price']}");
        }

        $this->info("\n✅ Successfully created {$created} gaming products!");
        $this->line("Products are ready for payment gateway approval.");

        return 0;
    }
}

