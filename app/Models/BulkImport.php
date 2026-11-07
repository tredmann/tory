<?php

namespace App\Models;

use App\Ai\ProviderFailure;
use App\BulkImports\BulkPhoto;
use App\BulkImports\PhotoGroup;
use App\BulkImports\StagedPhoto;
use App\BulkImports\StaleBulkImport;
use App\BulkImports\TimeGrouping;
use App\Enums\BulkImportStatus;
use App\Enums\GroupMark;
use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Jobs\ExtractImport;
use App\Jobs\GroupBulkImport;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\BulkImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * @property string $id
 * @property list<array{path: string, taken_at: string|null, name: string}> $photos
 * @property list<bool> $time_cuts
 * @property list<bool> $cuts
 * @property list<array{id: string, photos: list<string>, mark: string|null}>|null $groups
 * @property list<string> $discarded
 */
class BulkImport extends Model
{
    public const int MAX_PHOTOS = 100;

    /** @use HasFactory<BulkImportFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'spot_id',
        'status',
        'photos',
        'time_cuts',
        'cuts',
        'groups',
        'discarded',
        'grouping_incomplete',
        'failure_reason',
        'failure_provider',
    ];

    private bool $rowWasDeleted = false;

    protected static function booted(): void
    {
        static::deleted(function (BulkImport $bulkImport): void {
            // A confirm in another tab may have removed the row and handed the files to imports.
            if ($bulkImport->rowWasDeleted) {
                Storage::disk('local')->delete($bulkImport->allPaths());
            }
        });
    }

    protected function performDeleteOnModel(): void
    {
        $this->rowWasDeleted = $this->setKeysForSaveQuery($this->newModelQuery())->delete() > 0;

        $this->exists = false;
    }

    /**
     * @return array{status: 'App\\Enums\\BulkImportStatus', photos: 'array', time_cuts: 'array', cuts: 'array', groups: 'array', discarded: 'array', grouping_incomplete: 'boolean', failure_reason: 'App\\Enums\\ProviderFailureReason'}
     */
    protected function casts(): array
    {
        return [
            'status' => BulkImportStatus::class,
            'photos' => 'array',
            'time_cuts' => 'array',
            'cuts' => 'array',
            'groups' => 'array',
            'discarded' => 'array',
            'grouping_incomplete' => 'boolean',
            'failure_reason' => ProviderFailureReason::class,
        ];
    }

    /**
     * Stores the photos, orders them by capture time and starts the grouping.
     *
     * @param  list<StagedPhoto>  $photos
     *
     * @throws ValidationException
     */
    public static function start(User $user, int $spotId, array $photos): BulkImport
    {
        if ($photos === [] || count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'data.photos' => $photos === []
                    ? 'Mindestens ein Foto.'
                    : 'Höchstens '.self::MAX_PHOTOS.' Fotos pro Bulkimport.',
            ]);
        }

        return DB::transaction(function () use ($user, $spotId, $photos): BulkImport {
            $stored = [];

            try {
                foreach ($photos as $photo) {
                    $path = $photo->file->store('imports', 'local');

                    if ($path === false) {
                        throw new RuntimeException('Photo could not be stored.');
                    }

                    $stored[] = $path;

                    // A temporary upload reports its path even when the disk refused the write.
                    if (! Storage::disk('local')->exists($path) || Storage::disk('local')->size($path) !== $photo->file->getSize()) {
                        throw new RuntimeException('Photo could not be stored.');
                    }
                }

                $sorted = TimeGrouping::sort(array_map(
                    fn (StagedPhoto $photo, string $path): BulkPhoto => new BulkPhoto($path, $photo->takenAt, $photo->name),
                    $photos,
                    $stored,
                ));
                $cuts = TimeGrouping::cuts($sorted);

                $bulkImport = self::create([
                    'user_id' => $user->getKey(),
                    'spot_id' => $spotId,
                    'status' => BulkImportStatus::Grouping,
                    'photos' => array_map(fn (BulkPhoto $photo): array => [
                        'path' => $photo->path,
                        'taken_at' => $photo->takenAt?->toIso8601String(),
                        'name' => $photo->name,
                    ], $sorted),
                    'time_cuts' => $cuts,
                    'cuts' => $cuts,
                    'discarded' => [],
                ]);
            } catch (Throwable $e) {
                Storage::disk('local')->delete($stored);

                throw $e;
            }

            DB::afterCommit(fn () => GroupBulkImport::dispatch($bulkImport));

            return $bulkImport;
        });
    }

    /**
     * @return list<BulkPhoto>
     */
    public function photoList(): array
    {
        return array_map(fn (array $photo): BulkPhoto => new BulkPhoto(
            $photo['path'],
            $photo['taken_at'] === null ? null : new CarbonImmutable($photo['taken_at']),
            $photo['name'],
        ), $this->photos ?? []);
    }

    /**
     * @return list<PhotoGroup>
     */
    public function groupList(): array
    {
        return array_map(fn (array $group): PhotoGroup => new PhotoGroup(
            $group['id'],
            $group['photos'],
            ($group['mark'] ?? null) === null ? null : GroupMark::from($group['mark']),
        ), $this->groups ?? []);
    }

    /**
     * Every file this bulk import owns, including discarded photos.
     *
     * @return list<string>
     */
    public function allPaths(): array
    {
        return array_values(array_unique([
            ...array_column($this->photos ?? [], 'path'),
            ...($this->discarded ?? []),
        ]));
    }

    /**
     * Writes the cuts one window decided, starting at pair $firstPair.
     *
     * @param  list<bool>  $decided
     */
    public function storeCuts(int $firstPair, array $decided): void
    {
        $this->whileGrouping(function (BulkImport $row) use ($firstPair, $decided): void {
            $cuts = $row->cuts;

            foreach ($decided as $offset => $cut) {
                $cuts[$firstPair + $offset] = $cut;
            }

            $row->update(['cuts' => $cuts]);
        });
    }

    /**
     * Turns the current cuts into groups and makes the bulk import ready for review. A group is
     * marked merged when the AI removed a time cut inside it, otherwise split when the AI added
     * a cut at one of its edges.
     */
    public function finishGrouping(bool $incomplete, ?ProviderFailure $failure = null): void
    {
        $this->whileGrouping(function (BulkImport $row) use ($incomplete, $failure): void {
            $paths = array_column($row->photos, 'path');
            $cuts = $row->cuts;
            $timeCuts = $row->time_cuts;
            $groups = [];
            $first = 0;

            foreach ($paths as $index => $path) {
                $isLast = $index === count($paths) - 1;

                if (! $isLast && ! $cuts[$index]) {
                    continue;
                }

                $merged = false;

                for ($pair = $first; $pair < $index; $pair++) {
                    $merged = $merged || $timeCuts[$pair];
                }

                $split = ($first > 0 && ! $timeCuts[$first - 1])
                    || (! $isLast && ! $timeCuts[$index]);

                $groups[] = [
                    'id' => (string) Str::uuid7(),
                    'photos' => array_slice($paths, $first, $index - $first + 1),
                    'mark' => match (true) {
                        $merged => GroupMark::Merged->value,
                        $split => GroupMark::Split->value,
                        default => null,
                    },
                ];
                $first = $index + 1;
            }

            $row->update([
                'groups' => $groups,
                'grouping_incomplete' => $incomplete,
                'failure_reason' => $failure?->reason,
                'failure_provider' => $failure?->reason !== null ? $failure->providerName : null,
                'status' => BulkImportStatus::Ready,
            ]);
        });
    }

    /**
     * Runs the callback on the locked row while it is still grouping, then takes over its state.
     *
     * @param  Closure(BulkImport): void  $callback
     */
    private function whileGrouping(Closure $callback): void
    {
        DB::transaction(function () use ($callback): void {
            $row = self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($row === null || $row->status !== BulkImportStatus::Grouping) {
                return;
            }

            $callback($row);

            $this->setRawAttributes($row->getAttributes(), true);
        });
    }

    /**
     * Moves a photo to $position (clamped) in another or the same card. A card left without
     * photos disappears.
     *
     * @throws StaleBulkImport
     */
    public function movePhoto(string $path, string $groupId, int $position): void
    {
        $this->whileReady(function (BulkImport $row) use ($path, $groupId, $position): void {
            $groups = $row->groups ?? [];
            $from = self::cardOf($groups, $path);
            $to = self::cardIndex($groups, $groupId);

            $groups[$from]['photos'] = array_values(array_diff($groups[$from]['photos'], [$path]));
            $emptied = $from !== $to && $groups[$from]['photos'] === [];
            $groups[$from]['mark'] = null;

            $photos = $groups[$to]['photos'];
            array_splice($photos, max(0, min($position, count($photos))), 0, [$path]);
            $groups[$to]['photos'] = $photos;
            $groups[$to]['mark'] = null;

            if ($emptied) {
                array_splice($groups, $from, 1);
            }

            $row->update(['groups' => $groups]);
        });
    }

    /**
     * Ends the card holding $path before it; $path and the photos after it become a new card
     * right after.
     *
     * @throws StaleBulkImport
     */
    public function splitBefore(string $path): void
    {
        $this->whileReady(function (BulkImport $row) use ($path): void {
            $groups = $row->groups ?? [];
            $index = self::cardOf($groups, $path);
            $photos = $groups[$index]['photos'];
            $position = (int) array_search($path, $photos, true);

            if ($position === 0) {
                throw new StaleBulkImport;
            }

            $groups[$index]['photos'] = array_slice($photos, 0, $position);
            $groups[$index]['mark'] = null;

            array_splice($groups, $index + 1, 0, [[
                'id' => (string) Str::uuid7(),
                'photos' => array_slice($photos, $position),
                'mark' => null,
            ]]);

            $row->update(['groups' => $groups]);
        });
    }

    /**
     * Appends the photos of the next card to this one.
     *
     * @throws StaleBulkImport
     */
    public function mergeWithNext(string $groupId): void
    {
        $this->whileReady(function (BulkImport $row) use ($groupId): void {
            $groups = $row->groups ?? [];
            $index = self::cardIndex($groups, $groupId);

            if (! isset($groups[$index + 1])) {
                throw new StaleBulkImport;
            }

            $groups[$index]['photos'] = [...$groups[$index]['photos'], ...$groups[$index + 1]['photos']];
            $groups[$index]['mark'] = null;
            array_splice($groups, $index + 1, 1);

            $row->update(['groups' => $groups]);
        });
    }

    /**
     * Marks a photo as discarded or restores it. It stays in its card either way.
     *
     * @throws StaleBulkImport
     */
    public function setDiscarded(string $path, bool $discarded): void
    {
        $this->whileReady(function (BulkImport $row) use ($path, $discarded): void {
            self::cardOf($row->groups ?? [], $path);

            $others = array_values(array_diff($row->discarded ?? [], [$path]));

            $row->update(['discarded' => $discarded ? [...$others, $path] : $others]);
        });
    }

    /**
     * @return list<string>
     */
    public function remainingPhotos(PhotoGroup $group): array
    {
        return array_values(array_diff($group->photos, $this->discarded ?? []));
    }

    /**
     * The number of imports a confirm would create: cards with at least one remaining photo.
     */
    public function importCount(): int
    {
        return count(array_filter($this->groupList(), fn (PhotoGroup $group): bool => $this->remainingPhotos($group) !== []));
    }

    public function canConfirm(): bool
    {
        if ($this->status !== BulkImportStatus::Ready || $this->importCount() === 0) {
            return false;
        }

        foreach ($this->groupList() as $group) {
            if (count($this->remainingPhotos($group)) > Import::MAX_PHOTOS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turns every card with remaining photos into a pending import and removes the bulk import.
     * The imports take over the files; only the discarded files are deleted, after the commit.
     *
     * @return list<Import>
     *
     * @throws ModelNotFoundException<BulkImport>
     * @throws ValidationException
     */
    public function confirm(): array
    {
        return DB::transaction(function (): array {
            $row = self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($row === null) {
                throw (new ModelNotFoundException)->setModel(self::class, [$this->getKey()]);
            }

            if (! $row->canConfirm()) {
                throw ValidationException::withMessages([
                    'confirm' => 'Jede Gruppe braucht 1 bis '.Import::MAX_PHOTOS.' verbleibende Fotos.',
                ]);
            }

            $imports = [];

            foreach ($row->groupList() as $group) {
                $photos = $row->remainingPhotos($group);

                if ($photos === []) {
                    continue;
                }

                $imports[] = Import::create([
                    'user_id' => $row->user_id,
                    'spot_id' => $row->spot_id,
                    'status' => ImportStatus::Pending,
                    'photos' => $photos,
                ]);
            }

            self::query()->whereKey($row->getKey())->delete();

            $discarded = $row->discarded ?? [];

            DB::afterCommit(function () use ($imports, $discarded): void {
                foreach ($imports as $import) {
                    ExtractImport::dispatch($import);
                }

                if ($discarded === []) {
                    return;
                }

                try {
                    if (! Storage::disk('local')->delete($discarded)) {
                        report(new RuntimeException('Photo files could not be deleted: '.implode(', ', $discarded)));
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

            return $imports;
        });
    }

    /**
     * Runs the callback on the locked row while it is ready for review, then takes over its state.
     *
     * @param  Closure(BulkImport): void  $callback
     *
     * @throws StaleBulkImport
     */
    private function whileReady(Closure $callback): void
    {
        DB::transaction(function () use ($callback): void {
            $row = self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($row === null || $row->status !== BulkImportStatus::Ready) {
                throw new StaleBulkImport;
            }

            $callback($row);

            $this->setRawAttributes($row->getAttributes(), true);
        });
    }

    /**
     * @param  list<array{id: string, photos: list<string>, mark: string|null}>  $groups
     *
     * @throws StaleBulkImport
     */
    private static function cardOf(array $groups, string $path): int
    {
        foreach ($groups as $index => $group) {
            if (in_array($path, $group['photos'], true)) {
                return $index;
            }
        }

        throw new StaleBulkImport;
    }

    /**
     * @param  list<array{id: string, photos: list<string>, mark: string|null}>  $groups
     *
     * @throws StaleBulkImport
     */
    private static function cardIndex(array $groups, string $groupId): int
    {
        foreach ($groups as $index => $group) {
            if ($group['id'] === $groupId) {
                return $index;
            }
        }

        throw new StaleBulkImport;
    }

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
     * @return BelongsTo<Spot, $this>
     */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}
