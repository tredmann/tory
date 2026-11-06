<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\StorageLocation;
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
            'storage_location_id' => StorageLocation::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'photos' => ['imports/item.jpg'],
        ];
    }
}
