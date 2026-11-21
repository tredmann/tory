<?php

namespace App\Ai;

use App\Models\AiProvider;
use App\Models\Item;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Embeds a batch of items for the current embedding target. Shared by the
 * `items:embed` command and the ReembedItems job.
 */
final readonly class EmbeddingRunner
{
    /** The run stops after this many failures in a row, e.g. while the provider is down. */
    public const int MAX_CONSECUTIVE_FAILURES = 3;

    public function __construct(private ItemEmbedder $embedder) {}

    /**
     * Paging is by id, so filling the embedding column does not shift later
     * pages. With a budget the run stops before the first item that would
     * start after it and reports where to continue. The outcome is stored on
     * the provider noted at the start, only while it is still active for search.
     *
     * @param  Builder<Item>  $items
     * @param  (Closure(Item, Throwable): void)|null  $onFailure
     */
    public function run(
        Builder $items,
        ?string $afterId = null,
        ?int $budgetSeconds = null,
        int $pageSize = 500,
        ?Closure $onFailure = null,
        ?int $clientTimeout = null,
    ): EmbeddingRunResult {
        $provider = EmbeddingTarget::current()->aiProvider;
        $startedAt = now()->getTimestamp();
        $embedded = 0;
        $failures = 0;
        $consecutive = 0;
        $stoppedEarly = false;
        $resumeAfterId = null;
        $lastId = $afterId;
        $lastFailure = null;

        $items
            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
            ->eachById(function (Item $item) use (
                &$embedded, &$failures, &$consecutive, &$stoppedEarly, &$resumeAfterId, &$lastId, &$lastFailure,
                $startedAt, $budgetSeconds, $clientTimeout, $onFailure,
            ): ?bool {
                if ($budgetSeconds !== null && now()->getTimestamp() - $startedAt >= $budgetSeconds) {
                    $resumeAfterId = $lastId;

                    return false;
                }

                $lastId = $item->getKey();

                try {
                    if ($this->embedder->embedAndStore($item, $clientTimeout)) {
                        $embedded++;
                    }

                    $consecutive = 0;
                } catch (Throwable $e) {
                    report($e);
                    $failures++;
                    $consecutive++;
                    $lastFailure = $e instanceof ProviderFailure ? $e : null;

                    if ($onFailure !== null) {
                        $onFailure($item, $e);
                    }

                    if ($consecutive >= self::MAX_CONSECUTIVE_FAILURES) {
                        $stoppedEarly = true;

                        return false;
                    }
                }

                return null;
            }, $pageSize);

        if ($provider !== null) {
            AiProvider::query()
                ->whereKey($provider->getKey())
                ->where('is_active_for_search', true)
                ->update(AiProvider::searchFailureColumns($failures > 0, $lastFailure));
        }

        return new EmbeddingRunResult($embedded, $failures, $stoppedEarly, $resumeAfterId);
    }
}
