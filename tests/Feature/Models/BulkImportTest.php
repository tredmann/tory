<?php

use App\BulkImports\StagedPhoto;
use App\Enums\BulkImportStatus;
use App\Jobs\GroupBulkImport;
use App\Models\BulkImport;
use App\Models\Spot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function staged(string $name, ?string $time = null): StagedPhoto
{
    return new StagedPhoto(UploadedFile::fake()->image($name), $name, $time === null ? null : new CarbonImmutable($time));
}

test('start stores the photos, sorts them and sets time cuts', function () {
    Storage::fake('local');
    Queue::fake();
    $user = User::factory()->create();
    $spot = Spot::factory()->create();

    $bulk = BulkImport::start($user, $spot->id, [
        staged('late.jpg', '2026-01-01 10:05:00'),
        staged('early.jpg', '2026-01-01 10:00:00'),
    ]);

    expect($bulk->status)->toBe(BulkImportStatus::Grouping)
        ->and(array_map(fn ($p) => $p->name, $bulk->photoList()))->toBe(['early.jpg', 'late.jpg'])
        ->and($bulk->time_cuts)->toBe([true])
        ->and($bulk->cuts)->toBe($bulk->time_cuts)
        ->and($bulk->groupList())->toBe([]);

    foreach ($bulk->allPaths() as $path) {
        expect($path)->toStartWith('imports/');
        Storage::disk('local')->assertExists($path);
    }
});

test('start dispatches the grouping job after commit', function () {
    Storage::fake('local');
    Queue::fake();

    BulkImport::start(User::factory()->create(), Spot::factory()->create()->id, [staged('a.jpg')]);

    Queue::assertPushed(GroupBulkImport::class);
});

test('start rejects more than 100 photos and stores nothing', function () {
    Storage::fake('local');
    Queue::fake();
    $photos = array_map(fn (int $i) => staged("p{$i}.jpg"), range(1, 101));

    try {
        BulkImport::start(User::factory()->create(), Spot::factory()->create()->id, $photos);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors()['data.photos'])->toBe(['Höchstens 100 Fotos pro Bulkimport.']);
    }

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(BulkImport::count())->toBe(0);
});

test('deleting a bulk import deletes all its photo files', function () {
    Storage::fake('local');
    $bulk = BulkImport::factory()->grouping()->create();
    $bulk->update(['discarded' => ['imports/discarded.jpg']]);
    Storage::disk('local')->put('imports/discarded.jpg', 'x');
    $paths = $bulk->allPaths();

    expect($paths)->toContain('imports/discarded.jpg');

    $bulk->delete();

    foreach ($paths as $path) {
        Storage::disk('local')->assertMissing($path);
    }
});
