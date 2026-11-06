<?php

namespace App\Console\Commands;

use App\Ai\EmbeddingRunner;
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
    public const int MAX_CONSECUTIVE_FAILURES = EmbeddingRunner::MAX_CONSECUTIVE_FAILURES;

    /**
     * Number of items loaded per page; paging is by id, so filling the
     * embedding column does not shift later pages.
     */
    public static int $pageSize = 500;

    public function handle(EmbeddingRunner $runner): int
    {
        $result = $runner->run(
            Item::query()->when(! $this->option('all'), fn ($query) => $query->whereNull('embedding')),
            pageSize: self::$pageSize,
            onFailure: function (Item $item, Throwable $e): void {
                $this->warn("Gegenstand {$item->getKey()} konnte nicht eingebettet werden: {$e->getMessage()}");
            },
        );

        if ($result->stoppedEarly) {
            $this->warn(self::MAX_CONSECUTIVE_FAILURES.' Fehler in Folge, der Lauf wird vorzeitig beendet.');
        }

        $this->info("{$result->embedded} Gegenstände eingebettet.");

        return self::SUCCESS;
    }
}
