<?php

namespace Database\Factories;

use App\Models\Wallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Wallet::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'available_balance' => 0,
            'on_hold_balance' => 0,
            'withdrawn_total' => 0,
        ];
    }

    /**
     * Indicate that the wallet has some balance.
     */
    public function withBalance(float $amount = 100.00): static
    {
        return $this->state(fn (array $attributes) => [
            'available_balance' => $amount,
        ]);
    }

    /**
     * Indicate that the wallet has funds on hold.
     */
    public function withHold(float $amount = 50.00): static
    {
        return $this->state(fn (array $attributes) => [
            'on_hold_balance' => $amount,
        ]);
    }
}

