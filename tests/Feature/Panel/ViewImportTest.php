<?php

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

test('a pending import shows its status and polls', function () {
    $import = Import::factory()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertOk()
        ->assertSee('Wartet')
        ->assertSeeHtml('wire:poll.5s')
        ->assertActionHidden('retry');
});

test('a running import polls', function () {
    $import = Import::factory()->running()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Läuft')
        ->assertSeeHtml('wire:poll.5s');
});

test('a completed import shows title and description and stops polling', function () {
    $import = Import::factory()->completed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Fertig')
        ->assertSee('Raspberry Pi 4')
        ->assertSee('Grüne Platine mit vier USB-Anschlüssen.')
        ->assertDontSeeHtml('wire:poll')
        ->assertActionHidden('retry');
});

test('a status change in the database shows up on the next refresh', function () {
    $import = Import::factory()->create();

    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->assertSee('Wartet');

    $import->update(['status' => ImportStatus::Completed, 'title' => 'Neuer Titel', 'description' => 'Neue Beschreibung']);

    $page->call('$refresh')->assertSee('Fertig')->assertSee('Neuer Titel')->assertDontSeeHtml('wire:poll');
});

test('a failed import shows the error with debug on and offers retry', function () {
    config(['app.debug' => true]);
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Fehlgeschlagen')
        ->assertSee('Connection refused')
        ->assertDontSeeHtml('wire:poll')
        ->assertActionVisible('retry');
});

test('a failed import hides the raw error with debug off', function () {
    config(['app.debug' => false]);
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.')
        ->assertDontSee('Connection refused');
});

test('retry resets a failed import and dispatches the job again', function () {
    $import = Import::factory()->failed()->create(['title' => 'Alt', 'description' => 'Alt']);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('retry')
        ->assertNotified('Erneut gestartet')
        ->assertSee('Wartet')
        ->assertSeeHtml('wire:poll.5s');

    expect($import->fresh())
        ->status->toBe(ImportStatus::Pending)
        ->error->toBeNull()
        ->title->toBeNull()
        ->description->toBeNull();
    Queue::assertPushed(ExtractImport::class, fn (ExtractImport $job) => $job->import->is($import));
    Queue::assertPushed(ExtractImport::class, 1);
});

test('retry is hidden for running imports', function () {
    $import = Import::factory()->running()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->assertActionHidden('retry');
});

test('retry cannot be forced on a completed import', function () {
    $import = Import::factory()->completed()->create();

    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()]);

    expect(fn () => $page->callAction('retry'))->toThrow(ExpectationFailedException::class);
    expect($import->fresh()->status)->toBe(ImportStatus::Completed);
    Queue::assertNothingPushed();
});

test('retry cannot be forced on a pending import', function () {
    $import = Import::factory()->create();

    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()]);

    expect(fn () => $page->callAction('retry'))->toThrow(ExpectationFailedException::class);
    expect($import->fresh()->status)->toBe(ImportStatus::Pending);
    Queue::assertNothingPushed();
});

test('multi-line descriptions keep their line breaks', function () {
    $import = Import::factory()->completed()->create(['description' => "Zeile eins\nZeile zwei"]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Zeile eins')
        ->assertSee('Zeile zwei')
        ->assertSeeHtml('white-space: pre-line');
});

test('the view page shows the storage location', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->create(['storage_location_id' => $location->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee("Nr. {$location->id} · {$location->name}");
});

test('the view page renders an import without a storage location', function () {
    $import = Import::factory()->completed()->create(['storage_location_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(['Lagerort', '—']);
});
