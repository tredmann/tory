<?php

use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Jobs\EmbedItem;
use App\Jobs\ExtractImport;
use App\Models\AiProvider;
use App\Models\Import;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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

test('retry clears the search terms', function () {
    $import = Import::factory()->failed()->create(['search_terms' => ['Hefter']]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->callAction('retry');

    expect($import->fresh()->search_terms)->toBe([]);
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

test('the view page shows the spot', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->create(['spot_id' => $spot->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee("{$spot->code} · {$spot->name}");
});

test('the view page shows the location of the spot', function () {
    $spot = Spot::factory()->create(['location_id' => Location::factory()->create(['name' => 'Keller'])->id]);
    $import = Import::factory()->create(['spot_id' => $spot->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Keller → ');
});

test('the view page renders an import without a spot', function () {
    $import = Import::factory()->completed()->create(['spot_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(['Lagerplatz', '—']);
});

test('convert and delete are offered only for completed and failed imports', function (string $state, bool $visible) {
    $import = Import::factory()->{$state}()->create();
    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()]);

    $visible
        ? $page->assertActionVisible('convert')->assertActionVisible('delete')
        : $page->assertActionHidden('convert')->assertActionHidden('delete');
})->with([['pending', false], ['running', false], ['completed', true], ['failed', true]]);

test('convert is prefilled from the import', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create(['spot_id' => $spot->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->mountAction('convert')
        ->assertActionDataSet([
            'title' => 'Raspberry Pi 4',
            'description' => 'Grüne Platine mit vier USB-Anschlüssen.',
            'spot_id' => $spot->id,
        ]);
});

test('convert prefills the search terms', function () {
    $import = Import::factory()->completed()->create(['search_terms' => ['Tacker', 'Büro']]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->mountAction('convert')
        ->assertActionDataSet(['search_terms' => ['Tacker', 'Büro']]);
});

test('convert stores the edited search terms', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create(['search_terms' => ['Tacker', 'Büro'], 'spot_id' => $spot->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['search_terms' => ['Tacker']])
        ->assertHasNoActionErrors();

    expect(Item::sole()->search_terms)->toBe(['Tacker']);
});

test('the view page shows search terms', function () {
    $import = Import::factory()->completed()->create(['search_terms' => ['Tacker', 'Büro']]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Suchbegriffe')
        ->assertSee('Tacker')
        ->assertSee('Büro');
});

test('convert falls back to the last used spot when the import has none', function () {
    $spot = Spot::factory()->create();
    $user = User::factory()->create();
    $user->forceFill(['last_spot_id' => $spot->id])->save();
    $this->actingAs($user);
    $import = Import::factory()->completed()->create(['spot_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->mountAction('convert')
        ->assertActionDataSet(['spot_id' => $spot->id]);
});

test('convert with a corrected title creates the item and returns to the import list', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create(['spot_id' => $spot->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => 'Raspberry Pi 4 Model B'])
        ->assertHasNoActionErrors()
        ->assertNotified('Gegenstand angelegt')
        ->assertRedirect(ImportResource::getUrl('index'));

    $item = Item::sole();
    expect($item)->title->toBe('Raspberry Pi 4 Model B')->spot_id->toBe($spot->id)
        ->description->toBe('Grüne Platine mit vier USB-Anschlüssen.')
        ->and(Import::count())->toBe(0);
    Queue::assertPushed(EmbedItem::class, fn (EmbedItem $job) => $job->item->is($item));
});

test('convert remembers the chosen spot for the user', function () {
    $spot = Spot::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($user);
    $import = Import::factory()->completed()->create(['spot_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['spot_id' => $spot->id]);

    expect($user->fresh()->last_spot_id)->toBe($spot->id);
});

test('converting an import that was converted meanwhile answers 404 and creates nothing', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->completed()->create(['spot_id' => $spot->id]);
    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()]);
    Import::query()->whereKey($import->id)->delete();

    $page->call('mountAction', 'convert')->assertNotFound();

    expect(Item::count())->toBe(0);
});

test('a failed import can be filled in by hand', function () {
    $spot = Spot::factory()->create();
    $import = Import::factory()->failed()->create(['spot_id' => null]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => 'Lötkolben', 'spot_id' => $spot->id])
        ->assertHasNoActionErrors();

    expect(Item::sole())->title->toBe('Lötkolben')->spot_id->toBe($spot->id)
        ->and(Import::count())->toBe(0);
});

test('convert requires title and spot', function () {
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => '', 'spot_id' => null])
        ->assertHasActionErrors(['title' => 'required', 'spot_id' => 'required']);

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

test('a fixable failure names the provider and links to the settings with debug off', function () {
    $this->actingAs(User::factory()->admin()->create());
    config(['app.debug' => false]);
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::KeyRejected, 'Claude')->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Claude hat den API-Key abgelehnt.')
        ->assertSee('Provider prüfen')
        ->assertSeeHtml(AiProviderResource::getUrl('index'))
        ->assertDontSee('Connection refused')
        ->assertDontSee('Die Analyse ist fehlgeschlagen');
});

test('a member sees the failure without the provider link', function () {
    config(['app.debug' => false]);
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::KeyRejected, 'Claude')->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Claude hat den API-Key abgelehnt.')
        ->assertDontSee('Provider prüfen')
        ->assertDontSeeHtml('/ai-providers');
});

test('other failures keep the general message with debug off', function () {
    config(['app.debug' => false]);
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.')
        ->assertDontSee('Provider prüfen');
});

test('debug mode also shows the raw error of a fixable failure', function () {
    config(['app.debug' => true]);
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::Unreachable, 'Ollama')->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Ollama ist nicht erreichbar.')
        ->assertSee('Connection refused');
});

test('the failure sentence survives the provider being renamed or deleted', function () {
    $this->actingAs(User::factory()->admin()->create());
    config(['app.debug' => false]);
    expect(AiProvider::count())->toBe(0);
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::UnknownModel, 'Gelöschter Provider')->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Gelöschter Provider kennt das eingestellte Modell nicht.')
        ->assertSeeHtml(AiProviderResource::getUrl('index'));
});

test('retry clears the failure details and runs on the provider active now', function () {
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::KeyRejected, 'Claude')->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->callAction('retry');

    expect($import->fresh())->failure_reason->toBeNull()->failure_provider->toBeNull();
    Queue::assertPushed(ExtractImport::class);
});

test('a location can be created inside the spot picker of convert', function () {
    $import = Import::factory()->completed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction([
            TestAction::make('convert'),
            TestAction::make('createOption')->schemaComponent('spot_id', schema: 'mountedActionSchema0'),
            TestAction::make('createOption')->schemaComponent('location_id', schema: 'mountedActionSchema1'),
        ], ['name' => 'Keller']);

    expect(Location::firstWhere('name', 'Keller'))->not->toBeNull();
});
