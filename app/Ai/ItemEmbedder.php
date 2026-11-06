<?php

namespace App\Ai;

use App\Models\Item;
use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * Turns item text (and search queries) into vectors for semantic search.
 */
final class ItemEmbedder
{
    public const int DIMENSIONS = 1024;

    /**
     * @return list<float>
     */
    public function embed(string $text, ?int $timeout = null): array
    {
        $pending = Embeddings::for([$text])->dimensions(self::DIMENSIONS);

        if ($timeout !== null) {
            $pending->timeout($timeout);
        }

        $vector = $pending->generate(
            config('ai.inventory.embedding_provider'),
            config('ai.inventory.embedding_model'),
        )->embeddings[0] ?? [];

        if (count($vector) !== self::DIMENSIONS) {
            throw new RuntimeException('Das Embedding hat '.count($vector).' statt '.self::DIMENSIONS.' Dimensionen.');
        }

        return array_values($vector);
    }

    public function textFor(Item $item): string
    {
        $description = trim((string) $item->description);

        return $description === '' ? $item->title : "{$item->title}\n\n{$description}";
    }
}
