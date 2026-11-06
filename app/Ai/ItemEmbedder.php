<?php

namespace App\Ai;

use App\Models\Item;
use Illuminate\Support\Facades\DB;
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

    /**
     * Embeds the item's current text and stores the vector, unless the text
     * changed while the provider was working: the edit cleared the embedding
     * and queued a new job, so the stale vector is dropped. The row is locked
     * for the check and the write; no model event fires and `updated_at`
     * stays as it is.
     *
     * @return bool whether the vector was stored
     */
    public function embedAndStore(Item $item, ?int $timeout = null): bool
    {
        $text = $this->textFor($item);
        $vector = $this->embed($text, $timeout);

        return DB::transaction(function () use ($item, $text, $vector): bool {
            $current = Item::query()->lockForUpdate()->find($item->getKey());

            if ($current === null || $this->textFor($current) !== $text) {
                return false;
            }

            Item::withoutTimestamps(fn () => $current->forceFill(['embedding' => $vector])->saveQuietly());

            return true;
        });
    }

    public function textFor(Item $item): string
    {
        $description = trim((string) $item->description);

        return $description === '' ? $item->title : "{$item->title}\n\n{$description}";
    }
}
