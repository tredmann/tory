<?php

use App\Ai\Agents\PhotoBoundaryChecker;
use App\Ai\ProviderFailure;
use App\BulkImports\PhotoGroup;
use App\Enums\BulkImportStatus;
use App\Enums\GroupMark;
use App\Enums\ProviderFailureReason;
use App\Jobs\GroupBulkImport;
use App\Models\AiProvider;
use App\Models\BulkImport;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    AiProvider::factory()->active()->create();
    Storage::fake('local');
    Queue::fake();
    Exceptions::fake();
});

/**
 * @param  list<bool>  $timeCuts
 */
function bulkImportWith(int $photoCount, array $timeCuts = []): BulkImport
{
    $photos = array_map(fn (int $i): array => [
        'path' => "imports/photo-{$i}.jpg",
        'taken_at' => null,
        'name' => "IMG_{$i}.jpg",
    ], range(0, $photoCount - 1));
    $timeCuts = $timeCuts !== [] ? $timeCuts : array_fill(0, max(0, $photoCount - 1), false);

    return BulkImport::factory()->grouping()->create([
        'photos' => $photos,
        'time_cuts' => $timeCuts,
        'cuts' => $timeCuts,
    ]);
}

/**
 * @return list<string>
 */
function photoPaths(int ...$indexes): array
{
    return array_map(fn (int $i): string => "imports/photo-{$i}.jpg", $indexes);
}

/**
 * @return list<array{photos: list<string>, mark: GroupMark|null}>
 */
function groupsOf(BulkImport $bulkImport): array
{
    return array_map(
        fn (PhotoGroup $group): array => ['photos' => $group->photos, 'mark' => $group->mark],
        $bulkImport->fresh()->groupList(),
    );
}

function noBoundaries(): Closure
{
    return fn (string $prompt, Collection $attachments): array => ['boundaries' => array_fill(0, $attachments->count() - 1, false)];
}

function runGrouping(BulkImport $bulkImport, int $nextPair = 0): void
{
    new GroupBulkImport($bulkImport, $nextPair)->handle();
}

test('declares one attempt, a 200 second timeout and deletes when the model is missing', function (): void {
    $job = new GroupBulkImport(bulkImportWith(2));

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(200)
        ->and($job->deleteWhenMissingModels)->toBeTrue()
        ->and(GroupBulkImport::BUDGET_SECONDS)->toBe(150)
        ->and(GroupBulkImport::CLIENT_TIMEOUT)->toBe(45)
        ->and(GroupBulkImport::BUDGET_SECONDS + GroupBulkImport::CLIENT_TIMEOUT)->toBeLessThan($job->timeout);
});

test('the AI answers replace the time cuts and the groups follow', function (): void {
    PhotoBoundaryChecker::fake([['boundaries' => [false, true]]]);
    $bulkImport = bulkImportWith(3, [true, false]);

    runGrouping($bulkImport);

    $fresh = $bulkImport->fresh();
    expect($fresh->cuts)->toBe([false, true])
        ->and($fresh->time_cuts)->toBe([true, false])
        ->and($fresh->status)->toBe(BulkImportStatus::Ready)
        ->and($fresh->grouping_incomplete)->toBeFalse()
        ->and(groupsOf($bulkImport))->toBe([
            ['photos' => photoPaths(0, 1), 'mark' => GroupMark::Merged],
            ['photos' => photoPaths(2), 'mark' => GroupMark::Split],
        ]);
    expect(array_column($fresh->groups, 'id'))->each->toBeString()->not->toBeEmpty();
});

test('a merge across a time cut is marked merged', function (): void {
    PhotoBoundaryChecker::fake([['boundaries' => [false]]]);
    $bulkImport = bulkImportWith(2, [true]);

    runGrouping($bulkImport);

    expect(groupsOf($bulkImport))->toBe([
        ['photos' => photoPaths(0, 1), 'mark' => GroupMark::Merged],
    ]);
});

test('a split inside one time group is marked split', function (): void {
    PhotoBoundaryChecker::fake([
        ['boundaries' => [false, false, false, false, false, true, false]],
        ['boundaries' => [false, false, false, false, false, false]],
    ]);
    $bulkImport = bulkImportWith(12);

    runGrouping($bulkImport);

    expect(groupsOf($bulkImport))->toBe([
        ['photos' => photoPaths(...range(0, 5)), 'mark' => GroupMark::Split],
        ['photos' => photoPaths(...range(6, 11)), 'mark' => GroupMark::Split],
    ]);
});

test('each window sends at most eight photos', function (): void {
    PhotoBoundaryChecker::fake(noBoundaries());
    $bulkImport = bulkImportWith(20);

    runGrouping($bulkImport);

    PhotoBoundaryChecker::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() <= 8);
    PhotoBoundaryChecker::assertNotPrompted(fn ($prompt): bool => $prompt->attachments->count() > 8);
    expect($bulkImport->fresh()->status)->toBe(BulkImportStatus::Ready)
        ->and(groupsOf($bulkImport))->toHaveCount(1);
});

test('a provider failure keeps the decided cuts and records the reason', function (): void {
    AiProvider::query()->delete();
    AiProvider::factory()->active()->create(['name' => 'Claude schnell']);
    $calls = 0;
    PhotoBoundaryChecker::fake(function () use (&$calls): array {
        $calls++;

        if ($calls === 2) {
            throw new RequestException(new Response(new PsrResponse(401, [], '{}')));
        }

        return ['boundaries' => [false, false, true, false, false, false, false]];
    });
    $bulkImport = bulkImportWith(12);

    runGrouping($bulkImport);

    $fresh = $bulkImport->fresh();
    expect($fresh->cuts)->toBe([false, false, true, false, false, false, false, false, false, false, false])
        ->and($fresh->status)->toBe(BulkImportStatus::Ready)
        ->and($fresh->grouping_incomplete)->toBeTrue()
        ->and($fresh->failure_reason)->toBe(ProviderFailureReason::KeyRejected)
        ->and($fresh->failure_provider)->toBe('Claude schnell')
        ->and(groupsOf($bulkImport))->toHaveCount(2);
    Exceptions::assertReported(ProviderFailure::class);
});

test('the job resumes with a cursor when the budget runs out', function (): void {
    Carbon::setTestNow('2026-11-07 10:00:00');
    PhotoBoundaryChecker::fake(function (string $prompt, Collection $attachments): array {
        Carbon::setTestNow(now()->addSeconds(GroupBulkImport::BUDGET_SECONDS + 1));

        return ['boundaries' => [false, false, false, true, false, false, false]];
    });
    $bulkImport = bulkImportWith(12);

    runGrouping($bulkImport);

    $fresh = $bulkImport->fresh();
    expect($fresh->status)->toBe(BulkImportStatus::Grouping)
        ->and(array_slice($fresh->cuts, 0, 6))->toBe([false, false, false, true, false, false]);
    Queue::assertPushed(GroupBulkImport::class, fn (GroupBulkImport $job): bool => $job->nextPair === 6
        && $job->bulkImport->is($bulkImport));
    PhotoBoundaryChecker::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 8);
});

test('a resumed job starts at its cursor', function (): void {
    PhotoBoundaryChecker::fake([['boundaries' => [true, false, false, false, false, false]]]);
    $bulkImport = bulkImportWith(12);

    runGrouping($bulkImport, 6);

    $fresh = $bulkImport->fresh();
    expect($fresh->status)->toBe(BulkImportStatus::Ready)
        ->and($fresh->cuts)->toBe([false, false, false, false, false, false, false, false, false, false, false]);
    PhotoBoundaryChecker::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 7);
    Queue::assertNothingPushed();
});

test('failed() leaves a ready bulk import with the time groups', function (): void {
    $bulkImport = bulkImportWith(3, [false, true]);

    new GroupBulkImport($bulkImport)->failed(new RuntimeException('killed'));

    $fresh = $bulkImport->fresh();
    expect($fresh->status)->toBe(BulkImportStatus::Ready)
        ->and($fresh->grouping_incomplete)->toBeTrue()
        ->and(groupsOf($bulkImport))->toBe([
            ['photos' => photoPaths(0, 1), 'mark' => null],
            ['photos' => photoPaths(2), 'mark' => null],
        ]);
});

test('a deleted bulk import ends the job quietly', function (): void {
    $bulkImport = bulkImportWith(12);
    PhotoBoundaryChecker::fake(function (string $prompt, Collection $attachments) use ($bulkImport): array {
        $bulkImport->delete();

        return ['boundaries' => array_fill(0, $attachments->count() - 1, false)];
    });

    runGrouping($bulkImport);

    expect(BulkImport::query()->whereKey($bulkImport->getKey())->exists())->toBeFalse();
    Exceptions::assertNothingReported();
    Queue::assertNothingPushed();
    PhotoBoundaryChecker::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 8);
});

test('a single photo becomes one group without calling the AI', function (): void {
    PhotoBoundaryChecker::fake(fn (): never => throw new RuntimeException('must not be called'));
    $bulkImport = bulkImportWith(1);

    runGrouping($bulkImport);

    $fresh = $bulkImport->fresh();
    expect($fresh->status)->toBe(BulkImportStatus::Ready)
        ->and($fresh->grouping_incomplete)->toBeFalse()
        ->and(groupsOf($bulkImport))->toBe([['photos' => photoPaths(0), 'mark' => null]]);
    Exceptions::assertNothingReported();
});

test('without an active provider grouping keeps the time cuts and names the reason', function (): void {
    AiProvider::query()->delete();
    PhotoBoundaryChecker::fake()->preventStrayPrompts();
    $bulkImport = bulkImportWith(3, [false, true]);

    runGrouping($bulkImport);

    $fresh = $bulkImport->fresh();
    expect($fresh->status)->toBe(BulkImportStatus::Ready)
        ->and($fresh->grouping_incomplete)->toBeTrue()
        ->and($fresh->failure_reason)->toBe(ProviderFailureReason::NoProvider)
        ->and($fresh->failure_provider)->toBeNull()
        ->and(groupsOf($bulkImport))->toHaveCount(2);
});
