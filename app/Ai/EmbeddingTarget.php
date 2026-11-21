<?php

namespace App\Ai;

use App\Models\AiProvider;

/**
 * Where embeddings are computed: the provider active for search, otherwise the
 * `.env` configuration. The identity changes whenever the target does, so a
 * vector computed for another target can be recognised and dropped.
 */
final readonly class EmbeddingTarget
{
    public function __construct(
        public ?AiProvider $aiProvider,
        public string $model,
        public float $minSimilarity,
        private string $envProvider = '',
    ) {}

    public static function current(): self
    {
        $provider = AiProvider::activeForSearch();
        $configSimilarity = (float) config('ai.inventory.search_min_similarity');

        if ($provider === null) {
            return new self(
                null,
                (string) config('ai.inventory.embedding_model'),
                $configSimilarity,
                (string) config('ai.inventory.embedding_provider'),
            );
        }

        return new self(
            $provider,
            $provider->embedding_model ?? (string) config('ai.inventory.embedding_model'),
            $provider->search_min_similarity ?? $configSimilarity,
        );
    }

    public static function forProvider(AiProvider $provider, string $model): self
    {
        return new self($provider, $model, (float) config('ai.inventory.search_min_similarity'));
    }

    public function identity(): string
    {
        return $this->aiProvider === null
            ? "env:{$this->envProvider}:{$this->model}"
            : "provider:{$this->aiProvider->getKey()}:{$this->model}";
    }

    /**
     * The laravel/ai provider name. Decrypts the key of a configured provider,
     * so call it only where a failure is wrapped (see ItemEmbedder).
     */
    public function sdkProvider(): string
    {
        return $this->aiProvider?->toLaravelProvider()->name() ?? $this->envProvider;
    }
}
