<?php

use App\BulkImports\PhotoGroup;
use App\BulkImports\StaleBulkImport;
use App\Enums\GroupMark;
use App\Enums\ImportStatus;
use App\Jobs\ExtractImport;
use App\Models\BulkImport;
use App\Models\Import;
use App\Photos\PhotoStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
});

/**
 * A ready bulk import whose cards hold the given number of photos, with optional marks.
 *
 * @param  list<int>  $sizes
 * @param  list<string|null>  $marks
 */
function reviewBulk(array $sizes, array $marks = []): BulkImport
{
    $photos = [];
    $groups = [];

    foreach ($sizes as $index => $size) {
        $paths = array_map(fn (): string => 'imports/'.Str::uuid().'.jpg', range(1, $size));
        $photos = [...$photos, ...array_map(fn (string $path): array => ['path' => $path, 'taken_at' => null, 'name' => basename($path)], $paths)];
        $groups[] = ['id' => (string) Str::uuid7(), 'photos' => $paths, 'mark' => $marks[$index] ?? null];
    }

    $cuts = array_fill(0, count($photos) - 1, false);

    return BulkImport::factory()->state([
        'photos' => $photos,
        'time_cuts' => $cuts,
        'cuts' => $cuts,
    ])->ready($groups)->create();
}

/**
 * @return list<list<string>>
 */
function cardPhotos(BulkImport $bulk): array
{
    return array_map(fn (PhotoGroup $group): array => $group->photos, $bulk->fresh()->groupList());
}

test('a photo moves into another card at a position', function (): void {
    $bulk = reviewBulk([2, 2]);
    [$a, $b] = $bulk->groupList();

    $bulk->movePhoto($a->photos[1], $b->id, 1);

    expect(cardPhotos($bulk))->toBe([[$a->photos[0]], [$b->photos[0], $a->photos[1], $b->photos[1]]]);

    $bulk->movePhoto($a->photos[0], $b->id, 99);

    expect(cardPhotos($bulk))->toBe([[$b->photos[0], $a->photos[1], $b->photos[1], $a->photos[0]]]);
});

test('a card left empty by a move disappears', function (): void {
    $bulk = reviewBulk([1, 2]);
    [$a, $b] = $bulk->groupList();

    $bulk->movePhoto($a->photos[0], $b->id, 0);

    $groups = $bulk->fresh()->groupList();
    expect($groups)->toHaveCount(1)
        ->and($groups[0]->id)->toBe($b->id)
        ->and($groups[0]->photos)->toBe([$a->photos[0], ...$b->photos]);
});

test('splitting before a photo creates the next card', function (): void {
    $bulk = reviewBulk([3, 1]);
    [$a, $b] = $bulk->groupList();

    $bulk->splitBefore($a->photos[1]);

    $groups = $bulk->fresh()->groupList();
    expect(cardPhotos($bulk))->toBe([[$a->photos[0]], [$a->photos[1], $a->photos[2]], $b->photos])
        ->and($groups[0]->id)->toBe($a->id)
        ->and($groups[1]->id)->not->toBe($a->id)
        ->and($groups[2]->id)->toBe($b->id);
});

test('splitting before the first photo of a card is stale', function (): void {
    $bulk = reviewBulk([2]);
    $before = $bulk->fresh()->groups;

    expect(fn () => $bulk->splitBefore($bulk->groupList()[0]->photos[0]))->toThrow(StaleBulkImport::class)
        ->and($bulk->fresh()->groups)->toBe($before);
});

test('merging appends the next card', function (): void {
    $bulk = reviewBulk([1, 2, 1]);
    [$a, $b, $c] = $bulk->groupList();

    $bulk->mergeWithNext($a->id, $b->id);

    $groups = $bulk->fresh()->groupList();
    expect(cardPhotos($bulk))->toBe([[...$a->photos, ...$b->photos], $c->photos])
        ->and($groups[0]->id)->toBe($a->id);
});

test('repeating a merge is stale and leaves the following card alone', function (): void {
    $bulk = reviewBulk([1, 1, 1]);
    [$a, $b, $c] = $bulk->groupList();

    $bulk->mergeWithNext($a->id, $b->id);
    $before = $bulk->fresh()->groups;

    expect(fn () => $bulk->mergeWithNext($a->id, $b->id))->toThrow(StaleBulkImport::class)
        ->and($bulk->fresh()->groups)->toBe($before)
        ->and(cardPhotos($bulk)[1])->toBe($c->photos);
});

test('a merge whose expected next card is no longer next is stale', function (): void {
    $bulk = reviewBulk([1, 1, 1]);
    [$a, $b, $c] = $bulk->groupList();

    $bulk->movePhoto($b->photos[0], $c->id, 0);
    $before = $bulk->fresh()->groups;

    expect(fn () => $bulk->mergeWithNext($a->id, $b->id))->toThrow(StaleBulkImport::class)
        ->and(fn () => $bulk->mergeWithNext($a->id, $a->id))->toThrow(StaleBulkImport::class)
        ->and($bulk->fresh()->groups)->toBe($before);
});

test('merging the last card is stale', function (): void {
    $bulk = reviewBulk([1, 1]);

    expect(fn () => $bulk->mergeWithNext($bulk->groupList()[1]->id, $bulk->groupList()[0]->id))->toThrow(StaleBulkImport::class)
        ->and($bulk->fresh()->groupList())->toHaveCount(2);
});

test('discarding and restoring a photo', function (): void {
    $bulk = reviewBulk([3]);
    $group = $bulk->groupList()[0];

    $bulk->setDiscarded($group->photos[1], true);

    expect($bulk->fresh()->discarded)->toBe([$group->photos[1]])
        ->and($bulk->fresh()->remainingPhotos($bulk->fresh()->groupList()[0]))->toBe([$group->photos[0], $group->photos[2]]);

    $bulk->setDiscarded($group->photos[1], true);
    expect($bulk->fresh()->discarded)->toBe([$group->photos[1]]);

    $bulk->setDiscarded($group->photos[1], false);
    expect($bulk->fresh()->discarded)->toBe([]);
});

test('a card with only discarded photos stays and counts for no import', function (): void {
    $bulk = reviewBulk([1, 2]);
    [$a] = $bulk->groupList();

    $bulk->setDiscarded($a->photos[0], true);

    $fresh = $bulk->fresh();
    expect($fresh->groupList())->toHaveCount(2)
        ->and($fresh->remainingPhotos($fresh->groupList()[0]))->toBe([])
        ->and($fresh->importCount())->toBe(1)
        ->and($fresh->canConfirm())->toBeTrue();

    $bulk->setDiscarded($fresh->groupList()[1]->photos[0], true);
    $bulk->setDiscarded($fresh->groupList()[1]->photos[1], true);

    expect($bulk->fresh()->importCount())->toBe(0)
        ->and($bulk->fresh()->canConfirm())->toBeFalse();
});

test('an edit clears the AI mark of the cards it reshapes', function (): void {
    $marks = [GroupMark::Merged->value, GroupMark::Split->value, GroupMark::Merged->value, GroupMark::Split->value, GroupMark::Merged->value];
    $bulk = reviewBulk([2, 2, 2, 2, 2], $marks);
    [$a, $b, $c, $d, $e] = $bulk->groupList();

    $bulk->movePhoto($a->photos[0], $b->id, 0);
    expect(array_map(fn (PhotoGroup $g): ?GroupMark => $g->mark, $bulk->fresh()->groupList()))
        ->toBe([null, null, GroupMark::Merged, GroupMark::Split, GroupMark::Merged]);

    $bulk->splitBefore($c->photos[1]);
    expect(array_map(fn (PhotoGroup $g): ?GroupMark => $g->mark, $bulk->fresh()->groupList()))
        ->toBe([null, null, null, null, GroupMark::Split, GroupMark::Merged]);

    $bulk->mergeWithNext($d->id, $e->id);
    expect(array_map(fn (PhotoGroup $g): ?GroupMark => $g->mark, $bulk->fresh()->groupList()))
        ->toBe([null, null, null, null, null]);

    $marked = reviewBulk([2], [GroupMark::Merged->value]);
    $marked->setDiscarded($marked->groupList()[0]->photos[0], true);
    expect($marked->fresh()->groupList()[0]->mark)->toBe(GroupMark::Merged);
});

test('an unknown group or path is stale and changes nothing', function (): void {
    $bulk = reviewBulk([2, 1]);
    $other = reviewBulk([1]);
    $forged = $other->groupList()[0]->photos[0];
    $before = $bulk->fresh()->only(['groups', 'discarded']);
    [$a, $b] = $bulk->groupList();

    expect(fn () => $bulk->movePhoto($forged, $b->id, 0))->toThrow(StaleBulkImport::class)
        ->and(fn () => $bulk->movePhoto($a->photos[0], (string) Str::uuid7(), 0))->toThrow(StaleBulkImport::class)
        ->and(fn () => $bulk->splitBefore($forged))->toThrow(StaleBulkImport::class)
        ->and(fn () => $bulk->mergeWithNext($other->groupList()[0]->id, $b->id))->toThrow(StaleBulkImport::class)
        ->and(fn () => $bulk->setDiscarded($forged, true))->toThrow(StaleBulkImport::class)
        ->and($bulk->fresh()->only(['groups', 'discarded']))->toBe($before);
});

test('edits are refused while grouping', function (): void {
    $bulk = BulkImport::factory()->grouping()->create();
    $path = $bulk->allPaths()[0];

    expect(fn () => $bulk->setDiscarded($path, true))->toThrow(StaleBulkImport::class)
        ->and(fn () => $bulk->splitBefore($path))->toThrow(StaleBulkImport::class)
        ->and($bulk->fresh()->discarded)->toBe([]);
});

test('more than twenty remaining photos block confirm', function (): void {
    $bulk = reviewBulk([21]);

    expect($bulk->canConfirm())->toBeFalse();

    try {
        $bulk->confirm();
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('confirm');
    }

    expect(Import::query()->count())->toBe(0)
        ->and(BulkImport::query()->whereKey($bulk->id)->exists())->toBeTrue();

    $bulk->setDiscarded($bulk->groupList()[0]->photos[0], true);

    expect($bulk->fresh()->canConfirm())->toBeTrue();
});

test('confirm creates one import per card in order and hands over the files', function (): void {
    $bulk = reviewBulk([2, 1, 3]);
    [$a, $b, $c] = $bulk->groupList();
    $bulk->setDiscarded($b->photos[0], true);
    $bulk->setDiscarded($c->photos[1], true);

    $imports = $bulk->fresh()->confirm();

    expect($imports)->toHaveCount(2)
        ->and($imports[0]->photos)->toBe($a->photos)
        ->and($imports[1]->photos)->toBe([$c->photos[0], $c->photos[2]])
        ->and(BulkImport::query()->whereKey($bulk->id)->exists())->toBeFalse();

    foreach ($imports as $import) {
        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Pending)
            ->and($import->user_id)->toBe($bulk->user_id)
            ->and($import->spot_id)->toBe($bulk->spot_id);

        foreach ($import->photos as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }
});

test('confirm dispatches extraction for every import', function (): void {
    $bulk = reviewBulk([1, 2]);

    $imports = $bulk->confirm();

    Queue::assertPushed(ExtractImport::class, 2);
    foreach ($imports as $import) {
        Queue::assertPushed(ExtractImport::class, fn (ExtractImport $job) => $job->import->is($import));
    }
});

test('confirm deletes the discarded files only', function (): void {
    $bulk = reviewBulk([3]);
    $photos = $bulk->groupList()[0]->photos;
    $bulk->setDiscarded($photos[1], true);

    $bulk->fresh()->confirm();

    Storage::disk('local')->assertMissing($photos[1]);
    Storage::disk('local')->assertExists($photos[0]);
    Storage::disk('local')->assertExists($photos[2]);
});

test('a second confirm answers not found and creates nothing', function (): void {
    $bulk = reviewBulk([1, 1]);
    $first = BulkImport::query()->find($bulk->id);
    $second = BulkImport::query()->find($bulk->id);

    $first->confirm();

    expect(fn () => $second->confirm())->toThrow(ModelNotFoundException::class)
        ->and(Import::query()->count())->toBe(2);
});

test('confirm deletes the thumbnails of discarded photos and keeps the others', function (): void {
    $bulk = reviewBulk([3]);
    $photos = $bulk->groupList()[0]->photos;
    foreach ($photos as $photo) {
        Storage::disk('local')->put(PhotoStore::thumbnailPath($photo), 'thumb');
    }
    $bulk->setDiscarded($photos[1], true);

    $bulk->fresh()->confirm();

    Storage::disk('local')->assertMissing(PhotoStore::thumbnailPath($photos[1]));
    Storage::disk('local')->assertExists(PhotoStore::thumbnailPath($photos[0]));
    Storage::disk('local')->assertExists(PhotoStore::thumbnailPath($photos[2]));
});
