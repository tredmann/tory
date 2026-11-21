<?php

namespace App\Ai;

use App\Jobs\ReembedItems;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Tries an embedding model of a provider and switches search over to it.
 */
final class SearchProviderSwitch
{
    /**
     * Generous, because a local model may need a long time to load.
     */
    public const int PROBE_TIMEOUT_SECONDS = 120;

    public function __construct(private readonly ItemEmbedder $embedder) {}

    /**
     * Embeds a short text with the model. Returns null on success, otherwise
     * a German reason. Never cached, so the provider is really called.
     */
    public function probe(AiProvider $provider, string $model): ?string
    {
        try {
            $this->embedder->embedFor(
                EmbeddingTarget::forProvider($provider, $model),
                'Probe',
                self::PROBE_TIMEOUT_SECONDS,
            );

            return null;
        } catch (ProviderFailure $failure) {
            report($failure);

            return $failure->userMessage() ?? ProviderFailure::genericEmbeddingMessage();
        }
    }

    /**
     * Makes the provider the only one active for search with the given model
     * and empties every embedding (query builder: no events, `updated_at`
     * stays). The items update is deliberately unfiltered: it must wait for
     * the row lock of an in-flight store and overwrite it (see
     * ItemEmbedder::embedAndStore()). Rows are locked first, and the old flag
     * cleared before the new one is set, as in AiProvider::activate().
     */
    public function switchTo(AiProvider $provider, string $model): void
    {
        DB::transaction(function () use ($provider, $model): void {
            AiProvider::query()->lockForUpdate()->get(['id']);
            AiProvider::query()->where('is_active_for_search', true)->update(['is_active_for_search' => false]);
            $updated = AiProvider::query()->whereKey($provider->getKey())->update([
                'is_active_for_search' => true,
                'embedding_model' => $model,
                ...AiProvider::searchFailureColumns(false),
                'search_min_similarity' => DB::raw('coalesce(search_min_similarity, '.AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY.')'),
            ]);

            if ($updated === 0) {
                throw new ModelNotFoundException;
            }

            DB::table('items')->update(['embedding' => null]);

            ReembedItems::dispatch()->afterCommit();
        });

        $provider->is_active_for_search = true;
        $provider->embedding_model = $model;
        $provider->forceFill(AiProvider::searchFailureColumns(false));
        $provider->search_min_similarity ??= AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY;
        $provider->syncOriginalAttributes(['is_active_for_search', 'embedding_model', 'search_failure', 'search_failure_reason', 'search_failure_dimensions', 'search_failure_generic', 'search_min_similarity']);
    }
}
