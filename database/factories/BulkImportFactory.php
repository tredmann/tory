<?php

namespace Database\Factories;

use App\Enums\BulkImportStatus;
use App\Models\BulkImport;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<BulkImport>
 */
class BulkImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $photos = array_map(fn (int $i): array => [
            'path' => 'imports/'.fake()->uuid().'.jpg',
            'taken_at' => null,
            'name' => "IMG_{$i}.jpg",
        ], range(1, 3));

        return [
            'user_id' => User::factory(),
            'spot_id' => Spot::factory(),
            'status' => BulkImportStatus::Grouping,
            'photos' => $photos,
            'time_cuts' => [false, false],
            'cuts' => [false, false],
            'groups' => null,
            'discarded' => [],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BulkImport $bulkImport): void {
            foreach ($bulkImport->allPaths() as $path) {
                Storage::disk('local')->put($path, 'fake');
            }
        });
    }

    public function grouping(): static
    {
        return $this->state(['status' => BulkImportStatus::Grouping, 'groups' => null]);
    }

    /**
     * @param  list<array{id: string, photos: list<string>, mark: string|null}>  $groups
     */
    public function ready(array $groups = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BulkImportStatus::Ready,
            'groups' => $groups !== [] ? $groups : [[
                'id' => fake()->uuid(),
                'photos' => array_column($attributes['photos'], 'path'),
                'mark' => null,
            ]],
        ]);
    }
}
