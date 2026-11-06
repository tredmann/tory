<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'spot_id' => Spot::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'photos' => ['imports/item.jpg'],
        ];
    }
}
