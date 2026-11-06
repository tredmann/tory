<?php

namespace App\Console\Commands;

use App\Ai\ItemEmbedder;
use App\Models\Item;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('items:embed {--all : Alle Gegenstände neu einbetten, nicht nur die ohne Embedding}')]
#[Description('Berechnet fehlende Embeddings für Gegenstände')]
class EmbedItems extends Command
{
    /**
     * Number of items loaded per page; paging is by id, so filling the
     * embedding column does not shift later pages.
     */
    public static int $pageSize = 500;

    public function handle(ItemEmbedder $embedder): int
    {
        $embedded = 0;

        Item::query()
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('embedding'))
            ->eachById(function (Item $item) use ($embedder, &$embedded): void {
                try {
                    $vector = $embedder->embed($embedder->textFor($item));

                    $item->forceFill(['embedding' => $vector])->saveQuietly();
                    $embedded++;
                } catch (Throwable $e) {
                    report($e);

                    $this->warn("Gegenstand {$item->getKey()} konnte nicht eingebettet werden: {$e->getMessage()}");
                }
            }, self::$pageSize);

        $this->info("{$embedded} Gegenstände eingebettet.");

        return self::SUCCESS;
    }
}
