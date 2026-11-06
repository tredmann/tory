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

    /**
     * Returns null on success, otherwise a German reason.
     */
    public function run(AiProvider $provider): ?string
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
                ?? 'Der Test ist fehlgeschlagen. Bitte Modell und Zugangsdaten prüfen.';
        }
    }
}
