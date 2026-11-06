<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => ImportStatus::Pending,
            'photos' => ['imports/a.jpg', 'imports/b.jpg'],
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => ImportStatus::Pending]);
    }

    public function running(): static
    {
        return $this->state(['status' => ImportStatus::Running]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => ImportStatus::Completed,
            'title' => 'Raspberry Pi 4',
            'description' => 'Grüne Platine mit vier USB-Anschlüssen.',
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => ImportStatus::Failed,
            'error' => 'Connection refused',
        ]);
    }

    public function providerFailure(ProviderFailureReason $reason, string $providerName): static
    {
        return $this->state([
            'failure_reason' => $reason,
            'failure_provider' => $providerName,
        ]);
    }
}
