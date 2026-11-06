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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    /**
     * Removes and adds photos in one locked transaction and puts the overview photo first.
     * Removed files are deleted only after the commit; files stored for a failed save are deleted again.
     *
     * @param  list<string>  $removed  paths of photos to remove
     * @param  array<string, UploadedFile>  $uploads  new photos keyed by a client-made key
     * @param  string|null  $overview  a photo path or an upload key
     *
     * @throws ValidationException when the result would have no photo (unless it had none before) or more than 6
     */
    public function changePhotos(array $removed, array $uploads, ?string $overview): void
    {
        DB::transaction(function () use ($removed, $uploads, $overview): void {
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail(['id', 'photos']);
            /** @var list<string> $current */
            $current = $locked->photos ?? [];

            $remaining = array_values(array_diff($current, $removed));
            $count = count($remaining) + count($uploads);

            if ($count > 6) {
                throw ValidationException::withMessages(['data.photo_edits' => 'Höchstens 6 Fotos.']);
            }

            if ($count === 0 && $current !== []) {
                throw ValidationException::withMessages(['data.photo_edits' => 'Mindestens ein Foto.']);
            }

            $stored = [];

            try {
                foreach ($uploads as $key => $file) {
                    $stored[$key] = (string) $file->store('imports', 'local');
                }

                $first = $stored[$overview ?? ''] ?? (in_array($overview, $remaining, true) ? $overview : null);
                $ordered = array_values(array_unique([
                    ...($first === null ? [] : [$first]),
                    ...$remaining,
                    ...array_values($stored),
                ]));

                $this->photos = $current;
                $this->syncOriginalAttribute('photos');
                $this->update(['photos' => $ordered]);
            } catch (Throwable $e) {
                Storage::disk('local')->delete(array_values($stored));

                throw $e;
            }

            $deletable = array_values(array_intersect($current, $removed));

            DB::afterCommit(function () use ($deletable): void {
                try {
                    Storage::disk('local')->delete($deletable);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        });
    }
}
