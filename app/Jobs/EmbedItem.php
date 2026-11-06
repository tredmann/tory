<?php

namespace App\Jobs;

use App\Ai\ItemEmbedder;
use App\Models\Item;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Computes and stores the embedding of an item's current title and description.
 */
class EmbedItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Item $item) {}

    public function handle(ItemEmbedder $embedder): void
    {
        $vector = $embedder->embed($embedder->textFor($this->item), $this->timeout);

        $this->item->forceFill(['embedding' => $vector])->saveQuietly();
    }
}
