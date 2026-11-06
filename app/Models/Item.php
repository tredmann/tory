<?php

namespace App\Models;

use App\Ai\ItemEmbedder;
use App\Jobs\EmbedItem;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Hidden(['embedding'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'storage_location_id',
        'title',
        'description',
        'photos',
    ];

    protected static function booted(): void
    {
        static::created(function (Item $item): void {
            EmbedItem::dispatch($item)->afterCommit();
        });

        static::updating(function (Item $item): void {
            if ($item->isDirty(['title', 'description'])) {
                $item->embedding = null;
            }
        });

        static::updated(function (Item $item): void {
            if ($item->wasChanged(['title', 'description'])) {
                EmbedItem::dispatch($item)->afterCommit();
            }
        });

        static::deleted(function (Item $item): void {
            Storage::disk('local')->delete($item->photos ?? []);
        });
    }

    /**
     * @return array{photos: 'array', embedding: 'Illuminate\\Database\\Eloquent\\Casts\\AsVector'}
     */
    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'embedding' => AsVector::class,
        ];
    }

    /**
     * @return BelongsTo<StorageLocation, $this>
     */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /**
     * Finds items by keyword (every word in title or description) and, when
     * the query can be embedded, by meaning. Keyword matches rank first.
     *
     * @param  Builder<Item>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $patterns = self::keywordPatterns($term);

        $keywordCondition = function (Builder $group) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $group->where(function (Builder $word) use ($pattern): void {
                    $word->whereLike('title', $pattern)->orWhereLike('description', $pattern);
                });
            }
        };

        try {
            $vector = app(ItemEmbedder::class)->embed($term, timeout: 3);
        } catch (Throwable $exception) {
            report($exception);

            $query->where($keywordCondition);

            return;
        }

        $maxDistance = 1 - (float) config('ai.inventory.search_min_similarity');

        $query
            ->where(function (Builder $group) use ($keywordCondition, $vector, $maxDistance): void {
                $group->where($keywordCondition)
                    ->whereVectorDistanceLessThan('embedding', $vector, $maxDistance, 'or');
            })
            ->orderByRaw(
                'case when '.implode(' and ', array_fill(0, count($patterns), "(title ilike ? or coalesce(description, '') ilike ?)")).' then 0 else 1 end',
                array_merge(...array_map(fn (string $pattern): array => [$pattern, $pattern], $patterns)),
            )
            ->orderByVectorDistance('embedding', $vector);
    }

    /**
     * One LIKE pattern per whitespace-separated word, with `\`, `%` and `_` escaped.
     *
     * @return list<string>
     */
    private static function keywordPatterns(string $term): array
    {
        $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(
            fn (string $word): string => '%'.addcslashes($word, '\\%_').'%',
            $words,
        );
    }
}
