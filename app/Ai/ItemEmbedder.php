<?php

namespace App\Ai;

use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Turns item text (and search queries) into vectors for semantic search.
 */
final class ItemEmbedder
{
    public const int DIMENSIONS = 1024;

    /**
     * @return list<float>
     */
    public function embed(string $text, ?int $timeout = null, ?int $cacheSeconds = null): array
    {
        return $this->embedFor(EmbeddingTarget::current(), $text, $timeout, $cacheSeconds);
    }

    /**
     * With a configured provider every failure leaves as a ProviderFailure
     * (scrubbed); on the `.env` path it stays as it is.
     *
     * @return list<float>
     */
    public function embedFor(EmbeddingTarget $target, string $text, ?int $timeout = null, ?int $cacheSeconds = null): array
    {
        try {
            $pending = Embeddings::for([$text])->dimensions(self::DIMENSIONS);

            if ($timeout !== null) {
                $pending->timeout($timeout);
            }

            if ($cacheSeconds !== null) {
                $pending->cache($cacheSeconds);
            }

            $vector = $pending->generate($target->sdkProvider(), $target->model)->embeddings[0] ?? [];

            if (count($vector) !== self::DIMENSIONS) {
                throw new WrongEmbeddingDimensions(count($vector));
            }

            return array_values($vector);
        } catch (Throwable $e) {
            throw $target->aiProvider === null ? $e : ProviderFailure::from($e, $target->aiProvider);
        }
    }

    /**
     * Embeds the item's current text and stores the vector, unless the text
     * changed while the provider was working: the edit cleared the embedding
     * and queued a new job, so the stale vector is dropped. The row is locked
     * for the check and the write; no model event fires and `updated_at`
     * stays as it is. The same goes for a vector computed for another
     * embedding target than the current one (the provider was switched).
     *
     * The lock comes before reading the current target on purpose: a switch
     * updates the items afterwards, so its `UPDATE items` waits for this lock
     * and overwrites the row, while a switch committed before the lock is seen
     * by the target check.
     *
     * @return bool whether the vector was stored
     */
    public function embedAndStore(Item $item, ?int $timeout = null): bool
    {
        $text = $this->textFor($item);
        $target = EmbeddingTarget::current();
        $vector = $this->embedFor($target, $text, $timeout);

        return DB::transaction(function () use ($item, $text, $vector, $target): bool {
            $current = Item::query()->whereKey($item->getKey())->lockForUpdate()->first();

            if ($current === null
                || EmbeddingTarget::current()->identity() !== $target->identity()
                || $this->textFor($current) !== $text) {
                return false;
            }

            Item::withoutTimestamps(fn () => $current->forceFill(['embedding' => $vector])->saveQuietly());

            return true;
        });
    }

    public function textFor(Item $item): string
    {
        $parts = [$item->title];
        $description = trim((string) $item->description);

        if ($description !== '') {
            $parts[] = $description;
        }

        $terms = $item->search_terms ?? [];

        if ($terms !== []) {
            $parts[] = 'Suchbegriffe: '.implode(', ', $terms);
        }

        return implode("\n\n", $parts);
    }
}
