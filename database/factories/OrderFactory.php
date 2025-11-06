<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'buyer_id' => User::factory(),
            'seller_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'status' => 'pending',
            'tap_charge_id' => null,
            'paid_at' => null,
            'escrow_hold_at' => null,
            'escrow_release_at' => null,
            'completed_at' => null,
            'notes' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'paid_at' => now(),
            'tap_charge_id' => 'chg_' . fake()->uuid(),
        ]);
    }

    public function escrow(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'escrow_hold',
            'paid_at' => now(),
            'escrow_hold_at' => now(),
            'escrow_release_at' => now()->addHours(12),
            'tap_charge_id' => 'chg_' . fake()->uuid(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'escrow_hold_at' => now()->subDays(2),
            'escrow_release_at' => now()->subDays(1),
            'completed_at' => now()->subDay(),
            'tap_charge_id' => 'chg_' . fake()->uuid(),
        ]);
    }
}

