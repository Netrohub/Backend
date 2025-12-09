<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Listing;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test public can browse listings
     */
    public function test_public_can_browse_listings(): void
    {
        Listing::factory()->count(5)->create(['status' => 'active']);

        $response = $this->getJson('/api/v1/listings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'price',
                        'category',
                        'images',
                        'status',
                        'views',
                    ]
                ],
                // Pagination structure may vary
                'current_page',
                'per_page',
            ]);
    }

    /**
     * Test public can view single listing
     */
    public function test_public_can_view_single_listing(): void
    {
        $listing = Listing::factory()->create(['status' => 'active']);

        $response = $this->getJson("/api/v1/listings/{$listing->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $listing->id,
                'title' => $listing->title,
            ]);
    }

    /**
     * Test KYC verified user can create listing
     */
    public function test_kyc_verified_user_can_create_listing(): void
    {
        $user = User::factory()->create(['is_verified' => true]);
        Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/listings', [
                'title' => 'Test Listing',
                'description' => 'Test Description',
                'price' => 100.00,
                'category' => 'wos_accounts',
                'images' => [],
                'account_email' => 'test@account.com',
                'account_password' => 'AccountPass123!',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'title',
                'description',
                'price',
                'category',
            ]);

        $this->assertDatabaseHas('listings', [
            'title' => 'Test Listing',
            'user_id' => $user->id,
        ]);
    }

    /**
     * Test non-KYC verified user cannot create listing
     */
    public function test_non_kyc_verified_user_cannot_create_listing(): void
    {
        $user = User::factory()->create(['is_verified' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/listings', [
                'title' => 'Test Listing',
                'description' => 'Test Description',
                'price' => 100.00,
                'category' => 'wos_accounts',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'error_code' => 'KYC_NOT_VERIFIED',
            ]);
    }

    /**
     * Test user can update own listing
     */
    public function test_user_can_update_own_listing(): void
    {
        $user = User::factory()->create(['is_verified' => true]);
        $listing = Listing::factory()->create([
            'user_id' => $user->id,
            'title' => 'Original Title',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/listings/{$listing->id}", [
                'title' => 'Updated Title',
                'description' => $listing->description,
                'price' => $listing->price,
                'category' => $listing->category,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'title' => 'Updated Title',
            ]);

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'title' => 'Updated Title',
        ]);
    }

    /**
     * Test user cannot update other user's listing
     */
    public function test_user_cannot_update_other_users_listing(): void
    {
        $user = User::factory()->create(['is_verified' => true]);
        $otherUser = User::factory()->create(['is_verified' => true]);
        $listing = Listing::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/listings/{$listing->id}", [
                'title' => 'Hacked Title',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test user can delete own listing
     */
    public function test_user_can_delete_own_listing(): void
    {
        $user = User::factory()->create(['is_verified' => true]);
        $listing = Listing::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/listings/{$listing->id}");

        $response->assertStatus(200);

        // Should be soft deleted
        $this->assertSoftDeleted('listings', [
            'id' => $listing->id,
        ]);
    }

    /**
     * Test listing price validation
     */
    public function test_listing_price_must_be_positive(): void
    {
        $user = User::factory()->create(['is_verified' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/listings', [
                'title' => 'Test Listing',
                'description' => 'Test Description',
                'price' => -100.00,
                'category' => 'wos_accounts',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    /**
     * Test user can get their own listings
     */
    public function test_user_can_get_own_listings(): void
    {
        $user = User::factory()->create();
        Listing::factory()->count(3)->create(['user_id' => $user->id]);
        Listing::factory()->count(2)->create(); // Other user's listings

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/my-listings');

        $response->assertStatus(200);
        
        // Should only return user's listings
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * Test encrypted credentials are not exposed in listing details
     */
    public function test_encrypted_credentials_not_exposed_in_listing(): void
    {
        $listing = Listing::factory()->create([
            'status' => 'active',
        ]);

        // Set encrypted credentials
        $listing->account_email = 'test@account.com';
        $listing->account_password = 'TestPassword123';
        $listing->save();

        $response = $this->getJson("/api/v1/listings/{$listing->id}");

        $response->assertStatus(200)
            ->assertJsonMissing([
                'account_email' => 'test@account.com',
                'account_password' => 'TestPassword123',
                'account_email_encrypted',
                'account_password_encrypted',
            ]);
    }
}

