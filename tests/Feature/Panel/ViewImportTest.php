<?php

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Jobs\EmbedItem;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\Item;
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

test('convert and delete are offered only for completed and failed imports', function (string $state, bool $visible) {
    $import = Import::factory()->{$state}()->create();
    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()]);

    $visible
        ? $page->assertActionVisible('convert')->assertActionVisible('delete')
        : $page->assertActionHidden('convert')->assertActionHidden('delete');
})->with([['pending', false], ['running', false], ['completed', true], ['failed', true]]);

test('convert is prefilled from the import', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->completed()->create(['storage_location_id' => $location->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->mountAction('convert')
        ->assertActionDataSet([
            'title' => 'Raspberry Pi 4',
            'description' => 'Grüne Platine mit vier USB-Anschlüssen.',
            'storage_location_id' => $location->id,
        ]);
});

test('convert falls back to the last used location when the import has none', function () {
    $location = StorageLocation::factory()->create();
    $user = User::factory()->create();
    $user->forceFill(['last_storage_location_id' => $location->id])->save();
    $this->actingAs($user);
    $import = Import::factory()->completed()->create(['storage_location_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->mountAction('convert')
        ->assertActionDataSet(['storage_location_id' => $location->id]);
});

test('convert with a corrected title creates the item and returns to the import list', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->completed()->create(['storage_location_id' => $location->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => 'Raspberry Pi 4 Model B'])
        ->assertHasNoActionErrors()
        ->assertNotified('Gegenstand angelegt')
        ->assertRedirect(ImportResource::getUrl('index'));

    $item = Item::sole();
    expect($item)->title->toBe('Raspberry Pi 4 Model B')->storage_location_id->toBe($location->id)
        ->description->toBe('Grüne Platine mit vier USB-Anschlüssen.')
        ->and(Import::count())->toBe(0);
    Queue::assertPushed(EmbedItem::class, fn (EmbedItem $job) => $job->item->is($item));
});

test('convert remembers the chosen location for the user', function () {
    $location = StorageLocation::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);
    $import = Import::factory()->completed()->create(['storage_location_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['storage_location_id' => $location->id]);

    expect($user->fresh()->last_storage_location_id)->toBe($location->id);
});

test('converting an import that was converted meanwhile answers 404 and creates nothing', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->completed()->create(['storage_location_id' => $location->id]);
    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()]);
    Import::query()->whereKey($import->id)->delete();

    $page->call('mountAction', 'convert')->assertNotFound();

    expect(Item::count())->toBe(0);
});

test('a failed import can be filled in by hand', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->failed()->create(['storage_location_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => 'Lötkolben', 'storage_location_id' => $location->id])
        ->assertHasNoActionErrors();

    expect(Item::sole())->title->toBe('Lötkolben')->storage_location_id->toBe($location->id)
        ->and(Import::count())->toBe(0);
});

test('convert requires title and location', function () {
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => '', 'storage_location_id' => null])
        ->assertHasActionErrors(['title' => 'required', 'storage_location_id' => 'required']);

    expect(Item::count())->toBe(0)->and(Import::count())->toBe(1);
});

test('deleting an import removes the row and its photo files', function () {
    Storage::disk('local')->put('imports/y.jpg', 'y');
    $import = Import::factory()->completed()->create(['photos' => ['imports/y.jpg']]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(ImportResource::getUrl('index'));

    expect(Import::count())->toBe(0);
    Storage::disk('local')->assertMissing('imports/y.jpg');
});
