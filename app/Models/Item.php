<?php

namespace App\Models;

use App\Jobs\EmbedItem;
use App\Models\Concerns\RegeneratesTexts;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property list<string>|null $search_terms null on a model created in this request without the database default
 */
#[Hidden(['embedding'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use HasUuids;
    use RegeneratesTexts;

    /** @var list<string> */
    protected $fillable = [
        'spot_id',
        'title',
        'description',
        'photos',
        'search_terms',
    ];

    protected static function booted(): void
    {
        static::created(function (Item $item): void {
            EmbedItem::dispatch($item)->afterCommit();
        });

        static::updating(function (Item $item): void {
            if ($item->isDirty(['title', 'description', 'search_terms'])) {
                $item->embedding = null;
            }
        });

        static::updated(function (Item $item): void {
            if ($item->wasChanged(['title', 'description', 'search_terms'])) {
                EmbedItem::dispatch($item)->afterCommit();
            }
        });

        static::deleted(function (Item $item): void {
            Storage::disk('local')->delete($item->photos ?? []);
        });
    }

    /**
     * @return array{photos: 'array', search_terms: 'array', embedding: 'Illuminate\\Database\\Eloquent\\Casts\\AsVector'}
     */
    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'search_terms' => 'array',
            'embedding' => AsVector::class,
        ];
    }

    /**
     * @return BelongsTo<Spot, $this>
     */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}
