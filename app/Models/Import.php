<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'storage_location_id',
        'status',
        'photos',
        'title',
        'description',
        'error',
        'failure_reason',
        'failure_provider',
    ];

    protected static function booted(): void
    {
        static::deleted(function (Import $import): void {
            Storage::disk('local')->delete($import->photos ?? []);
        });
    }

    /**
     * @return array{status: 'App\\Enums\\ImportStatus', photos: 'array', failure_reason: 'App\\Enums\\ProviderFailureReason'}
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'photos' => 'array',
            'failure_reason' => ProviderFailureReason::class,
        ];
    }

    /**
     * The fixed sentence for a failure the user can fix on "Eigene KI". It
     * uses the stored provider name, so it survives renaming or deleting.
     */
    public function failureMessage(): ?string
    {
        return $this->failure_reason?->message($this->failure_provider ?? 'Der KI-Provider');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StorageLocation, $this>
     */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /**
     * Turns this import into an item that takes over its photos.
     *
     * The import row is deleted first, with a query-builder delete that takes the row
     * lock and fires no model event, so the "deleted" listener does not remove the
     * photo files, which now belong to the item. When the row is already gone (a
     * concurrent conversion or deletion), nothing is created.
     *
     * @throws ModelNotFoundException
     */
    public function convertToItem(string $title, ?string $description, int $storageLocationId): Item
    {
        return DB::transaction(function () use ($title, $description, $storageLocationId): Item {
            if (static::query()->whereKey($this->getKey())->delete() === 0) {
                throw (new ModelNotFoundException)->setModel(static::class, [$this->getKey()]);
            }

            return Item::create([
                'storage_location_id' => $storageLocationId,
                'title' => $title,
                'description' => $description,
                'photos' => $this->photos,
            ]);
        });
    }
}
