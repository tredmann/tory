<?php

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Jobs\EmbedItem;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

test('the list shows items newest first with their spot', function () {
    $spot = Spot::factory()->create(['name' => 'Elektronik-Kiste']);
    $old = Item::factory()->create(['spot_id' => $spot->id, 'created_at' => now()->subDay()]);
    $new = Item::factory()->create(['spot_id' => $spot->id]);

    Livewire::test(ListItems::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->assertSee("{$spot->code} · Elektronik-Kiste")
        ->assertSeeHtml("/spots/{$spot->code}");
});

test('items cannot be created directly', function () {
    expect(ItemResource::canCreate())->toBeFalse()
        ->and(ItemResource::getPages())->not->toHaveKey('create');
});

test('the view page shows title, description and spot', function () {
    $spot = Spot::factory()->create(['name' => 'Schrank']);
    $item = Item::factory()->create([
        'spot_id' => $spot->id,
        'title' => 'Akkuschrauber',
        'description' => 'Gelb, mit Ladegerät',
    ]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Akkuschrauber')
        ->assertSee('Gelb, mit Ladegerät')
        ->assertSee("{$spot->code} · Schrank")
        ->assertSeeHtml("/spots/{$spot->code}");
});

test('the view page shows search terms', function () {
    $item = Item::factory()->create(['search_terms' => ['Akkubohrer', 'Werkzeug']]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Suchbegriffe')
        ->assertSee('Akkubohrer')
        ->assertSee('Werkzeug');
});

test('the edit page removes a search term', function () {
    $item = Item::factory()->create(['search_terms' => ['A', 'B']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['search_terms' => ['A']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->search_terms)->toBe(['A']);
});

test('editing changes the title and moves the item to another spot', function () {
    $item = Item::factory()->create();
    $target = Spot::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel', 'spot_id' => $target->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->spot_id->toBe($target->id);
});

test('title and spot are required when editing', function () {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => '', 'spot_id' => null])
        ->call('save')
        ->assertHasFormErrors(['title' => 'required', 'spot_id' => 'required']);
});

test('the edit page offers no delete action', function () {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

test('deleting an item removes the row and its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $item = Item::factory()->create(['photos' => ['imports/x.jpg']]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])->callAction('delete');

    expect(Item::count())->toBe(0);
    Storage::disk('local')->assertMissing('imports/x.jpg');
});

test('the search field above the list finds items by keyword and by meaning', function () {
    $keyword = Item::factory()->create(['title' => 'Kunststoffgehäuse']);
    $semantic = Item::factory()->create(['title' => 'Pi-Hülle', 'created_at' => now()->addMinute()]);
    $semantic->forceFill(['embedding' => axis(1)])->saveQuietly();
    $other = Item::factory()->create(['title' => 'Gartenschlauch']);
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn () => axis(1), $prompt->inputs));

    Livewire::test(ListItems::class)
        ->searchTable('Gehäuse')
        ->assertCanSeeTableRecords([$keyword, $semantic], inOrder: true)
        ->assertCanNotSeeTableRecords([$other]);
});

test('the list and view page show the location', function () {
    $spot = Spot::factory()->create([
        'location_id' => Location::factory()->create(['name' => 'Keller'])->id,
    ]);
    $item = Item::factory()->create(['spot_id' => $spot->id]);

    Livewire::test(ListItems::class)
        ->assertSee('Keller → ')
        ->assertSeeHtml("/spots/{$spot->code}");

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Keller → ')
        ->assertSeeHtml("/spots/{$spot->code}");
});

test('filters items by location', function () {
    $keller = Location::factory()->create(['name' => 'Keller']);
    $garage = Location::factory()->create(['name' => 'Garage']);
    $inKeller = Item::factory()->create(['spot_id' => Spot::factory()->create(['location_id' => $keller->id])->id]);
    $inGarage = Item::factory()->create(['spot_id' => Spot::factory()->create(['location_id' => $garage->id])->id]);

    Livewire::test(ListItems::class)
        ->filterTable('location', $keller->getKey())
        ->assertCanSeeTableRecords([$inKeller])
        ->assertCanNotSeeTableRecords([$inGarage]);
});

test('the location filter narrows a text search', function () {
    $keller = Location::factory()->create(['name' => 'Keller']);
    $garage = Location::factory()->create(['name' => 'Garage']);
    $kellerSpot = Spot::factory()->create(['location_id' => $keller->id]);
    $inKeller = Item::factory()->create(['title' => 'Hammerfix', 'spot_id' => $kellerSpot->id]);
    $unrelated = Item::factory()->create(['title' => 'Gießkanne', 'spot_id' => $kellerSpot->id]);
    $inGarage = Item::factory()->create(['title' => 'Hammerfix', 'spot_id' => Spot::factory()->create(['location_id' => $garage->id])->id]);

    Livewire::test(ListItems::class)
        ->searchTable('Hammerfix')
        ->filterTable('location', $keller->getKey())
        ->assertCanSeeTableRecords([$inKeller])
        ->assertCanNotSeeTableRecords([$inGarage, $unrelated]);
});

test('a location can be created inside the spot picker on the edit page', function () {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->callAction([
            TestAction::make('createOption')->schemaComponent('spot_id'),
            TestAction::make('createOption')->schemaComponent('location_id', schema: 'mountedActionSchema0'),
        ], ['name' => 'Keller'])
        ->assertHasNoFormErrors();

    expect(Location::firstWhere('name', 'Keller'))->not->toBeNull();
});

test('the edit page marks the first photo for the overview', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldVisible('overview_photo')
        ->assertFormSet(['overview_photo' => 'imports/a.jpg'])
        ->assertSee('Foto für die Übersicht')
        ->assertSeeHtml('value="imports/a.jpg"')
        ->assertSeeHtml('value="imports/b.jpg"');
});

test('saving a chosen overview photo moves it to the front and keeps the others in order', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['overview_photo' => 'imports/c.jpg'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/c.jpg', 'imports/a.jpg', 'imports/b.jpg']);
});

test('saving without choosing another photo keeps the order', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
});

test('the overview photo field is hidden for an item with one photo and saving keeps the photo', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldHidden('overview_photo')
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('an item without photos can be edited', function () {
    $item = Item::factory()->create(['photos' => []]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldHidden('overview_photo')
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->photos->toBe([]);
});

test('a path that is not a photo of the item is rejected as overview photo', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['overview_photo' => 'imports/other.jpg'])
        ->call('save')
        ->assertHasFormErrors(['overview_photo']);

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg']);
});

test('choosing an overview photo does not recompute the embedding', function () {
    $embedding = array_fill(0, 1024, 0.1);
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg'], 'embedding' => $embedding]);
    Queue::fake();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['overview_photo' => 'imports/b.jpg'])
        ->call('save')
        ->assertHasNoFormErrors();

    Queue::assertNotPushed(EmbedItem::class);
    expect($item->fresh()->embedding)->not->toBeNull();
});
