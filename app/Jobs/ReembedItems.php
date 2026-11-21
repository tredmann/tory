<?php

namespace App\Jobs;

use App\Ai\EmbeddingRunner;
use App\Ai\ProviderFailure;
use App\Models\AiProvider;
use App\Models\Item;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Computes the missing embeddings, e.g. after the embedding provider changed.
 * When its time budget runs out it queues itself to continue after the last
 * item it handled. 150 s plus one 45 s client call stays under the timeout.
 */
class ReembedItems implements ShouldQueue
{
    use Queueable;

    public const int BUDGET_SECONDS = 150;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public ?string $afterId = null) {}

    public function handle(EmbeddingRunner $runner): void
    {
        $result = $runner->run(
            Item::query()->whereNull('embedding'),
            $this->afterId,
            self::BUDGET_SECONDS,
            clientTimeout: EmbedItem::CLIENT_TIMEOUT,
        );

        if ($result->resumeAfterId !== null) {
            self::dispatch($result->resumeAfterId);
        }
    }

    /**
     * Records why the chain ended, e.g. when the worker killed the job. Only
     * the reason of a provider failure or a generic marker is stored, never a
     * raw exception message.
     */
    public function failed(?Throwable $exception): void
    {
        AiProvider::query()->where('is_active_for_search', true)->update(
            AiProvider::searchFailureColumns(true, $exception instanceof ProviderFailure ? $exception : null),
        );
    }
}
