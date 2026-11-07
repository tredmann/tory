<?php

namespace App\Models;

use App\Ai\ProviderFailure;
use App\BulkImports\BulkPhoto;
use App\BulkImports\PhotoGroup;
use App\BulkImports\StagedPhoto;
use App\BulkImports\TimeGrouping;
use App\Enums\BulkImportStatus;
use App\Enums\GroupMark;
use App\Enums\ProviderFailureReason;
use App\Jobs\GroupBulkImport;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\BulkImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    protected static function booted(): void
    {
        static::deleted(function (BulkImport $bulkImport): void {
            Storage::disk('local')->delete($bulkImport->allPaths());
        });
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
