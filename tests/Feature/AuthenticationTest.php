<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user registration with valid data
     */
    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'TestP@ssw0rd!2024$Strong',
            'password_confirmation' => 'TestP@ssw0rd!2024$Strong',
            'phone' => '+966500000000',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'created_at',
                    'updated_at',
                    'wallet',
                ],
                'token',
                'message',
            ]);

        // Check user was created
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        // Check wallet was created
        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user->wallet);
        $this->assertEquals(0, $user->wallet->available_balance);
    }

    /**
     * Test user registration fails with weak password
     */
    public function test_user_cannot_register_with_weak_password(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Test user registration fails with duplicate email
     */
    public function test_user_cannot_register_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!@#',
            'password_confirmation' => 'Password123!@#',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test user can login with correct credentials
     */
    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('TestP@ssw0rd!2024$Strong'),
        ]);

        // Create wallet for user
        if (!$user->wallet) {
            Wallet::factory()->create(['user_id' => $user->id]);
        }

        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'TestP@ssw0rd!2024$Strong',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user',
                'token',
            ]);
    }

    /**
     * Test user cannot login with incorrect credentials
     */
    public function test_user_cannot_login_with_incorrect_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('TestP@ssw0rd!2024$Strong'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'WrongP@ssw0rd!2024$Wrong',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test authenticated user can access protected routes
     */
    public function test_authenticated_user_can_access_protected_routes(): void
    {
        $user = User::factory()->create();
        
        // Create wallet for user
        if (!$user->wallet) {
            Wallet::factory()->create(['user_id' => $user->id]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/user');

        $response->assertStatus(200)
            ->assertJson([
                'id' => $user->id,
                'email' => $user->email,
            ]);
    }

    /**
     * Test unauthenticated user cannot access protected routes
     */
    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        $response = $this->getJson('/api/v1/user');

        $response->assertStatus(401);
    }

    /**
     * Test user can logout
     */
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/logout');

        $response->assertStatus(200);

        // Token should be revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'name' => 'test-token',
            'tokenable_id' => $user->id,
        ]);
    }

    /**
     * Test email case sensitivity handling
     */
    public function test_login_handles_email_case_insensitivity(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('TestP@ssw0rd!2024$Strong'),
        ]);

        // Create wallet for user
        if (!$user->wallet) {
            Wallet::factory()->create(['user_id' => $user->id]);
        }

        // Try logging in with uppercase email
        $response = $this->postJson('/api/v1/login', [
            'email' => 'TEST@EXAMPLE.COM',
            'password' => 'TestP@ssw0rd!2024$Strong',
        ]);

        $response->assertStatus(200);
    }
}

