<?php

namespace App\Ai;

use App\Ai\Agents\InventoryExtractor;
use App\Models\AiProvider;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Sends a small bundled image through the same structured-output path as a
 * real extraction, using the provider's saved values. Nothing is stored.
 */
final class ProviderTester
{
    /**
     * Generous, because a local model may need a long time to load.
     */
    public const TIMEOUT_SECONDS = 120;

    public function __construct(private readonly SearchProviderSwitch $searchSwitch) {}

    /**
     * Tests each configured role. Returns null on success, otherwise a German
     * reason; when both roles are configured and one fails, both are named.
     */
    public function run(AiProvider $provider): ?string
    {
        $extraction = filled($provider->model) ? $this->extractionReason($provider) : false;
        $embedding = filled($provider->embedding_model)
            ? $this->searchSwitch->probe($provider, $provider->embedding_model)
            : false;

        if ($extraction === false || $embedding === false) {
            return $extraction === false ? ($embedding ?: null) : $extraction;
        }

        if ($extraction === null && $embedding === null) {
            return null;
        }

        return __('Image recognition: :extraction Embedding: :embedding', [
            'extraction' => $extraction ?? __('OK.'),
            'embedding' => $embedding ?? __('OK.'),
        ]);
    }

    private function extractionReason(AiProvider $provider): ?string
    {
        try {
            InventoryExtractor::make(aiProvider: $provider)->extract(
                [Image::fromPath(resource_path('ai/test-image.png'), 'image/png')],
                self::TIMEOUT_SECONDS,
            );

            return null;
        } catch (Throwable $e) {
            $failure = ProviderFailure::from($e, $provider);
            report($failure);

            return $failure->reason?->message($provider->name)
                ?? __('The test failed. Please check the model and credentials.');
        }
    }
}
