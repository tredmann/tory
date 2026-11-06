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
    /** The run stops after this many failures in a row, e.g. while the provider is down. */
    public const int MAX_CONSECUTIVE_FAILURES = 3;

    /**
     * Number of items loaded per page; paging is by id, so filling the
     * embedding column does not shift later pages.
     */
    public static int $pageSize = 500;

    public function handle(ItemEmbedder $embedder): int
    {
        $embedded = 0;
        $failures = 0;

        Item::query()
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('embedding'))
            ->eachById(function (Item $item) use ($embedder, &$embedded, &$failures): ?bool {
                try {
                    if ($embedder->embedAndStore($item)) {
                        $embedded++;
                    }

                    $failures = 0;
                } catch (Throwable $e) {
                    report($e);
                    $failures++;

                    $this->warn("Gegenstand {$item->getKey()} konnte nicht eingebettet werden: {$e->getMessage()}");

                    if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                        $this->warn(self::MAX_CONSECUTIVE_FAILURES.' Fehler in Folge, der Lauf wird vorzeitig beendet.');

                        return false;
                    }
                }

                return null;
            }, self::$pageSize);

        $this->info("{$embedded} Gegenstände eingebettet.");

        return self::SUCCESS;
    }
}
