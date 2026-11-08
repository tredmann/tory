<?php

use App\Jobs\EmbedItem;
use App\Jobs\GenerateThumbnail;
use App\Models\Item;
use App\Photos\PhotoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
});

function itemWithPhotos(array $names = ['a', 'b', 'c']): Item
{
    $paths = [];
    foreach ($names as $name) {
        $paths[] = "imports/{$name}.jpg";
        Storage::disk('local')->put("imports/{$name}.jpg", $name);
    }

    $item = Item::factory()->create(['photos' => $paths]);
    Queue::fake();

    return $item;
}

function newPhoto(): UploadedFile
{
    return UploadedFile::fake()->image('new.jpg');
}

function storedFiles(): array
{
    return Storage::disk('local')->files('imports');
}

test('an upload is appended and the overview photo stays first', function () {
    $item = itemWithPhotos();

    $item->changePhotos([], ['k' => newPhoto()], null);

    $photos = $item->fresh()->photos;
    expect($photos)->toHaveCount(4)
        ->and(array_slice($photos, 0, 3))->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
    Storage::disk('local')->assertExists($photos[3]);
});

test('choosing an upload puts it first', function () {
    $item = itemWithPhotos();

    $item->changePhotos([], ['k' => newPhoto()], 'k');

    $photos = $item->fresh()->photos;
    expect($photos)->toHaveCount(4)
        ->and(array_slice($photos, 1))->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
    Storage::disk('local')->assertExists($photos[0]);
});

test('choosing an existing photo keeps the others in order', function () {
    $item = itemWithPhotos();

    $item->changePhotos([], [], 'imports/c.jpg');

    expect($item->fresh()->photos)->toBe(['imports/c.jpg', 'imports/a.jpg', 'imports/b.jpg']);
});

test('removing the overview photo moves the next one forward', function () {
    $item = itemWithPhotos();

    $item->changePhotos(['imports/a.jpg'], [], 'imports/a.jpg');

    expect($item->fresh()->photos)->toBe(['imports/b.jpg', 'imports/c.jpg']);
    Storage::disk('local')->assertMissing('imports/a.jpg');
    Storage::disk('local')->assertExists(['imports/b.jpg', 'imports/c.jpg']);
});

test('the only photo can be replaced in one save', function () {
    $item = itemWithPhotos(['a']);

    $item->changePhotos(['imports/a.jpg'], ['k' => newPhoto()], null);

    $photos = $item->fresh()->photos;
    expect($photos)->toHaveCount(1)->and($photos[0])->not->toBe('imports/a.jpg');
    Storage::disk('local')->assertMissing('imports/a.jpg');
    Storage::disk('local')->assertExists($photos[0]);
});

test('zero photos are rejected and nothing changes', function () {
    $item = itemWithPhotos();

    try {
        $item->changePhotos(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg'], [], null);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['data.photo_edits'][0])->toContain('Mindestens ein Foto');
    }

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
    Storage::disk('local')->assertExists(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
});

test('more than twenty photos are rejected and nothing changes', function () {
    $item = itemWithPhotos(range('a', 't'));

    try {
        $item->changePhotos([], ['k' => newPhoto()], null);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['data.photo_edits'][0])->toContain('Höchstens 20 Fotos');
    }

    expect($item->fresh()->photos)->toHaveCount(20)->and(storedFiles())->toHaveCount(20);
});

test('twenty photos are accepted', function () {
    $item = itemWithPhotos(range('a', 's'));

    $item->changePhotos([], ['k' => newPhoto()], null);

    expect($item->fresh()->photos)->toHaveCount(20);
});

test('an item without photos may stay without photos', function () {
    $item = Item::factory()->create(['photos' => []]);

    $item->changePhotos([], [], null);

    expect($item->fresh()->photos)->toBe([]);
});

test('a stale removal cannot bring back a deleted photo', function () {
    $item = itemWithPhotos();
    Item::query()->whereKey($item->id)->update(['photos' => json_encode(['imports/b.jpg', 'imports/c.jpg'])]);

    $item->changePhotos([], [], 'imports/a.jpg');

    expect($item->fresh()->photos)->toBe(['imports/b.jpg', 'imports/c.jpg']);
});

test('a forged removal touches no file', function () {
    $item = itemWithPhotos();
    Storage::disk('local')->put('imports/other.jpg', 'other');

    $item->changePhotos(['imports/other.jpg'], [], null);

    Storage::disk('local')->assertExists('imports/other.jpg');
    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
});

test('a forged overview falls back to the first photo', function () {
    $item = itemWithPhotos();

    $item->changePhotos([], [], 'imports/other.jpg');

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
});

test('a failed save stores nothing and deletes nothing', function () {
    $item = itemWithPhotos();
    Item::updating(function () {
        throw new RuntimeException('save failed');
    });

    expect(fn () => $item->changePhotos(['imports/a.jpg'], ['k' => newPhoto()], null))
        ->toThrow(RuntimeException::class);

    Storage::disk('local')->assertExists('imports/a.jpg');
    expect(storedFiles())->toHaveCount(3)
        ->and($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
});

test('changing photos keeps the embedding and dispatches no job', function () {
    $item = itemWithPhotos();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->save();

    $item->changePhotos(['imports/a.jpg'], ['k' => newPhoto()], null);

    Queue::assertNotPushed(EmbedItem::class);
    expect($item->fresh()->embedding)->not->toBeNull();
});

test('a failed file write stores nothing and deletes nothing', function () {
    $item = itemWithPhotos(['a']);
    breakLocalDisk(writesBeforeFailure: 1);

    expect(fn () => $item->changePhotos(['imports/a.jpg'], ['k1' => newPhoto(), 'k2' => newPhoto()], null))
        ->toThrow(RuntimeException::class);

    Storage::disk('local')->assertExists('imports/a.jpg');
    expect(storedFiles())->toBe(['imports/a.jpg'])
        ->and($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('a removed file stays until the commit and a rollback keeps it', function () {
    $item = itemWithPhotos();

    expect(fn () => DB::transaction(function () use ($item): void {
        $item->changePhotos(['imports/a.jpg'], [], null);

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class, 'rolled back');

    Storage::disk('local')->assertExists('imports/a.jpg');
    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
});

test('a file that cannot be deleted is reported and the save stands', function () {
    $item = itemWithPhotos();
    breakLocalDisk(failsDeletes: true);
    Exceptions::fake();

    $item->changePhotos(['imports/a.jpg'], [], null);

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'imports/a.jpg'));
    expect($item->fresh()->photos)->toBe(['imports/b.jpg', 'imports/c.jpg']);
});

test('a model that lags behind the row still writes the change', function () {
    $item = itemWithPhotos(['a', 'b']);
    Item::query()->whereKey($item->id)->update(['photos' => json_encode(['imports/b.jpg'])]);
    $stale = $item->fresh();
    Item::query()->whereKey($item->id)->update(['photos' => json_encode(['imports/a.jpg', 'imports/b.jpg'])]);

    $stale->changePhotos(['imports/a.jpg'], [], null);

    expect($item->fresh()->photos)->toBe(['imports/b.jpg']);
    Storage::disk('local')->assertMissing('imports/a.jpg');
    Storage::disk('local')->assertExists('imports/b.jpg');
});

test('removing a photo removes its thumbnail', function () {
    $item = itemWithPhotos();
    Storage::disk('local')->put(PhotoStore::thumbnailPath('imports/a.jpg'), 'thumb');
    Storage::disk('local')->put(PhotoStore::thumbnailPath('imports/b.jpg'), 'thumb');

    $item->changePhotos(['imports/a.jpg'], [], null);

    Storage::disk('local')->assertMissing(PhotoStore::thumbnailPath('imports/a.jpg'));
    Storage::disk('local')->assertExists(PhotoStore::thumbnailPath('imports/b.jpg'));
});

test('only added photos get a thumbnail queued', function () {
    $item = itemWithPhotos(['a']);

    $item->changePhotos([], ['k' => newPhoto()], null);

    $added = $item->fresh()->photos[1];
    Queue::assertPushedOn('thumbnails', GenerateThumbnail::class, fn (GenerateThumbnail $job): bool => $job->path === $added);
    Queue::assertPushedTimes(GenerateThumbnail::class, 1);
});
