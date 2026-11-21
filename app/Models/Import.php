<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Jobs\ExtractImport;
use App\Models\Concerns\RegeneratesTexts;
use App\Photos\PhotoStore;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property list<string>|null $search_terms null on a model created in this request without the database default
 */
#[Fillable([
    'user_id',
    'spot_id',
    'status',
    'photos',
    'title',
    'description',
    'search_terms',
    'error',
    'failure_reason',
    'failure_provider',
])]
class Import extends Model
{
    public const int MAX_PHOTOS = 20;

    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    use HasUuids;
    use RegeneratesTexts;

    protected static function booted(): void
    {
        static::deleted(function (Import $import): void {
            PhotoStore::delete($import->photos ?? []);
        });
    }

    /**
     * @return array{status: 'App\\Enums\\ImportStatus', photos: 'array', search_terms: 'array', failure_reason: 'App\\Enums\\ProviderFailureReason'}
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'photos' => 'array',
            'search_terms' => 'array',
            'failure_reason' => ProviderFailureReason::class,
        ];
    }

    /**
     * The fixed sentence for a failure the user can fix on "Eigene KI". It
     * uses the stored provider name, so it survives renaming or deleting.
     */
    public function failureMessage(): ?string
    {
        return $this->failure_reason?->message($this->failure_provider ?? __('The AI provider'));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [ImportStatus::Completed, ImportStatus::Failed], true);
    }

    /**
     * Clears the result and the failure of a finished import and extracts it again.
     */
    public function restart(): void
    {
        $this->update([
            'status' => ImportStatus::Pending,
            'error' => null,
            'failure_reason' => null,
            'failure_provider' => null,
            'title' => null,
            'description' => null,
            'search_terms' => [],
        ]);

        ExtractImport::dispatch($this);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Spot, $this>
     */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    /**
     * Turns this import into an item that takes over its photos.
     *
     * The import row is deleted first, with a query-builder delete that takes the row
     * lock and fires no model event, so the "deleted" listener does not remove the
     * photo files, which now belong to the item. When the row is already gone (a
     * concurrent conversion or deletion), nothing is created.
     *
     * @param  array<int, string>  $searchTerms
     *
     * @throws ModelNotFoundException
     */
    public function convertToItem(string $title, ?string $description, int $spotId, array $searchTerms = []): Item
    {
        return DB::transaction(function () use ($title, $description, $spotId, $searchTerms): Item {
            if (static::query()->whereKey($this->getKey())->delete() === 0) {
                throw (new ModelNotFoundException)->setModel(static::class, [$this->getKey()]);
            }

            return Item::create([
                'spot_id' => $spotId,
                'title' => $title,
                'description' => $description,
                'search_terms' => $searchTerms,
                'photos' => $this->photos,
            ]);
        });
    }
}
