<?php

namespace App\Ai;

use App\Models\AiProvider;

/**
 * Where embeddings are computed: the provider active for search. The identity
 * changes whenever the target does, so a vector computed for another target
 * can be recognised and dropped.
 */
final readonly class EmbeddingTarget
{
    public function __construct(
        public AiProvider $aiProvider,
        public string $model,
        public float $minSimilarity,
    ) {}

    public static function current(): ?self
    {
        $provider = AiProvider::activeForSearch();

        if ($provider === null) {
            return null;
        }

        return new self(
            $provider,
            (string) $provider->embedding_model,
            $provider->search_min_similarity ?? AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY,
        );
    }

    public static function forProvider(AiProvider $provider, string $model): self
    {
        return new self($provider, $model, AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY);
    }

    public function identity(): string
    {
        return "provider:{$this->aiProvider->getKey()}:{$this->model}";
    }

    /**
     * The laravel/ai provider name. Decrypts the provider's key, so call it
     * only where a failure is wrapped (see ItemEmbedder).
     */
    public function sdkProvider(): string
    {
        return $this->aiProvider->toLaravelProvider()->name();
    }
}
