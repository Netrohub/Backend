<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\User;
use App\Constants\ListingCategories;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Listing>
 */
class ListingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Listing::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 10, 1000),
            'category' => fake()->randomElement(ListingCategories::all()),
            'images' => [],
            'status' => 'active',
            'views' => fake()->numberBetween(0, 1000),
            'account_metadata' => [],
        ];
    }

    /**
     * Indicate that the listing is sold.
     */
    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sold',
        ]);
    }

    /**
     * Indicate that the listing is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the listing has images.
     */
    public function withImages(int $count = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'images' => array_map(fn() => fake()->imageUrl(), range(1, $count)),
        ]);
    }
}

