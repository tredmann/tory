<?php

namespace App\Ai;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Finds items by keyword (every word in title or description) and, when the
 * query can be embedded, by meaning. Keyword matches rank first.
 */
final class ItemSearch
{
    public function __construct(private ItemEmbedder $embedder) {}

    /**
     * @param  Builder<Item>  $query
     */
    public function apply(Builder $query, string $term): void
    {
        $term = trim($term);

        $keyword = $term === '' ? null : self::keywordCondition($term);

        if ($keyword === null) {
            return;
        }

        try {
            $vector = $this->embedder->embed($term, timeout: 3);
        } catch (Throwable $exception) {
            report($exception);

            $query->whereRaw($keyword['sql'], $keyword['bindings']);

            return;
        }

        $maxDistance = 1 - (float) config('ai.inventory.search_min_similarity');

        $query
            ->where(function (Builder $group) use ($keyword, $vector, $maxDistance): void {
                $group->whereRaw($keyword['sql'], $keyword['bindings'])
                    ->whereVectorDistanceLessThan('embedding', $vector, $maxDistance, 'or');
            })
            ->orderByRaw("case when {$keyword['sql']} then 0 else 1 end", $keyword['bindings'])
            ->orderByVectorDistance('embedding', $vector);
    }

    /**
     * The keyword condition as one SQL fragment with bound patterns: every
     * whitespace-separated word must occur in title or description, with
     * `\`, `%` and `_` escaped. Used for both filtering and ranking.
     *
     * @return array{sql: literal-string, bindings: list<string>}|null null when the term has no words
     */
    private static function keywordCondition(string $term): ?array
    {
        $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return null;
        }

        /** @var list<literal-string> $sql */
        $sql = [];
        $bindings = [];

        foreach ($words as $word) {
            $pattern = '%'.addcslashes($word, '\\%_').'%';
            $sql[] = "(title ilike ? or coalesce(description, '') ilike ?)";
            array_push($bindings, $pattern, $pattern);
        }

        return ['sql' => '('.implode(' and ', $sql).')', 'bindings' => $bindings];
    }
}
