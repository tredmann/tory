<?php

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
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
