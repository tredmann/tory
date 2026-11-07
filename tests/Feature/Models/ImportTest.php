<?php

use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use App\Photos\PhotoStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

test('status labels and colours', function () {
    expect(ImportStatus::Pending->getLabel())->toBe('Wartet')
        ->and(ImportStatus::Running->getLabel())->toBe('Läuft')
        ->and(ImportStatus::Completed->getLabel())->toBe('Fertig')
        ->and(ImportStatus::Failed->getLabel())->toBe('Fehlgeschlagen')
        ->and(ImportStatus::Pending->getColor())->toBe('gray')
        ->and(ImportStatus::Running->getColor())->toBe('warning')
        ->and(ImportStatus::Completed->getColor())->toBe('success')
        ->and(ImportStatus::Failed->getColor())->toBe('danger');
});

test('pending and running are active, completed and failed are not', function () {
    expect(ImportStatus::Pending->isActive())->toBeTrue()
        ->and(ImportStatus::Running->isActive())->toBeTrue()
        ->and(ImportStatus::Completed->isActive())->toBeFalse()
        ->and(ImportStatus::Failed->isActive())->toBeFalse();
});

test('an import casts its status and photos and belongs to a user', function () {
    $import = Import::factory()->failed()->create();

    expect($import->fresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg'])
        ->and($import->fresh()->error)->toBe('Connection refused')
        ->and($import->user)->toBeInstanceOf(User::class);
});

test('factory states set the matching status', function () {
    expect(Import::factory()->create()->status)->toBe(ImportStatus::Pending)
        ->and(Import::factory()->running()->create()->status)->toBe(ImportStatus::Running)
        ->and(Import::factory()->completed()->create())
        ->status->toBe(ImportStatus::Completed)
        ->title->toBe('Raspberry Pi 4');
});

test('deleting an import deletes its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $import = Import::factory()->create(['photos' => ['imports/x.jpg']]);

    $import->delete();

    Storage::disk('local')->assertMissing('imports/x.jpg');
});

test('deleting an import quietly keeps its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    Import::factory()->create(['photos' => ['imports/x.jpg']])->deleteQuietly();

    Storage::disk('local')->assertExists('imports/x.jpg');
});

test('converting creates the item, removes the import and keeps the files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create(['photos' => ['imports/x.jpg']]);

    $item = $import->convertToItem('Pi-Gehäuse', 'Schwarz', $spot->id);

    expect($item)->title->toBe('Pi-Gehäuse')->description->toBe('Schwarz')
        ->photos->toBe(['imports/x.jpg'])->spot_id->toBe($spot->id)
        ->and(Import::count())->toBe(0);
    Storage::disk('local')->assertExists('imports/x.jpg');
});

test('conversion takes over the search terms', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create();

    $item = $import->convertToItem('Tacker', null, $spot->id, ['Hefter', 'Büro']);

    expect($item->fresh()->search_terms)->toBe(['Hefter', 'Büro']);
});

test('an import that was converted already cannot be converted again', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create(['photos' => ['imports/x.jpg']]);
    $stale = Import::find($import->id);

    $import->convertToItem('Erster', null, $spot->id);

    expect(fn () => $stale->convertToItem('Zweiter', null, $spot->id))
        ->toThrow(ModelNotFoundException::class);
    expect(Item::count())->toBe(1);
    Storage::disk('local')->assertExists('imports/x.jpg');
});

test('restart resets a failed import and dispatches extraction', function () {
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::KeyRejected, 'Claude')->create([
        'title' => 'Alt',
        'description' => 'Alt',
        'search_terms' => ['a'],
    ]);

    $import->restart();

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->error)->toBeNull()
        ->and($import->failure_reason)->toBeNull()
        ->and($import->failure_provider)->toBeNull()
        ->and($import->title)->toBeNull()
        ->and($import->description)->toBeNull()
        ->and($import->search_terms)->toBe([]);
    Queue::assertPushed(ExtractImport::class);
});

test('an import is finished when completed or failed', function () {
    expect(Import::factory()->completed()->make()->isFinished())->toBeTrue()
        ->and(Import::factory()->failed()->make()->isFinished())->toBeTrue()
        ->and(Import::factory()->pending()->make()->isFinished())->toBeFalse()
        ->and(Import::factory()->running()->make()->isFinished())->toBeFalse();
});

test('deleting an import deletes the thumbnails with the photos', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    Storage::disk('local')->put(PhotoStore::thumbnailPath('imports/x.jpg'), 'thumb');
    $import = Import::factory()->create(['photos' => ['imports/x.jpg']]);

    $import->delete();

    Storage::disk('local')->assertMissing('imports/x.jpg');
    Storage::disk('local')->assertMissing(PhotoStore::thumbnailPath('imports/x.jpg'));
});

test('converting keeps the original and its thumbnail', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    Storage::disk('local')->put(PhotoStore::thumbnailPath('imports/x.jpg'), 'thumb');
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create(['photos' => ['imports/x.jpg']]);

    $import->convertToItem('Pi-Gehäuse', 'Schwarz', $spot->id);

    Storage::disk('local')->assertExists('imports/x.jpg');
    Storage::disk('local')->assertExists(PhotoStore::thumbnailPath('imports/x.jpg'));
});
