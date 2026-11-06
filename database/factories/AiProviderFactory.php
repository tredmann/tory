<?php

namespace Database\Factories;

use App\Enums\AiVendor;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'vendor' => AiVendor::Anthropic,
            'api_key' => 'sk-'.fake()->unique()->regexify('[a-z0-9]{24}'),
            'url' => null,
            'model' => 'claude-sonnet-4-5',
            'is_active' => false,
        ];
    }

    public function ollama(): static
    {
        return $this->state(fn (): array => [
            'vendor' => AiVendor::Ollama,
            'api_key' => null,
            'url' => 'http://host.docker.internal:11434',
            'model' => 'gemma4:e4b-mlx',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }
}
