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
    public function handle(ItemEmbedder $embedder): int
    {
        $embedded = 0;

        Item::query()
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('embedding'))
            ->orderBy('id')
            ->each(function (Item $item) use ($embedder, &$embedded): void {
                try {
                    $vector = $embedder->embed($embedder->textFor($item));

                    $item->forceFill(['embedding' => $vector])->saveQuietly();
                    $embedded++;
                } catch (Throwable $e) {
                    report($e);

                    $this->warn("Gegenstand {$item->getKey()} konnte nicht eingebettet werden: {$e->getMessage()}");
                }
            });

        $this->info("{$embedded} Gegenstände eingebettet.");

        return self::SUCCESS;
    }
}
