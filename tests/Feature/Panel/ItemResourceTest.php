<?php

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Models\Item;
use App\Models\StorageLocation;
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

test('the list shows items newest first with their location', function () {
    $location = StorageLocation::factory()->create(['name' => 'Elektronik-Kiste']);
    $old = Item::factory()->create(['storage_location_id' => $location->id, 'created_at' => now()->subDay()]);
    $new = Item::factory()->create(['storage_location_id' => $location->id]);

    Livewire::test(ListItems::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->assertSee("Nr. {$location->id} · Elektronik-Kiste");
});

test('items cannot be created directly', function () {
    expect(ItemResource::canCreate())->toBeFalse()
        ->and(ItemResource::getPages())->not->toHaveKey('create');
});

test('the view page shows title, description and location', function () {
    $location = StorageLocation::factory()->create(['name' => 'Schrank']);
    $item = Item::factory()->create([
        'storage_location_id' => $location->id,
        'title' => 'Akkuschrauber',
        'description' => 'Gelb, mit Ladegerät',
    ]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Akkuschrauber')
        ->assertSee('Gelb, mit Ladegerät')
        ->assertSee("Nr. {$location->id} · Schrank");
});

test('editing changes the title and moves the item to another location', function () {
    $item = Item::factory()->create();
    $target = StorageLocation::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel', 'storage_location_id' => $target->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->storage_location_id->toBe($target->id);
});

test('title and location are required when editing', function () {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => '', 'storage_location_id' => null])
        ->call('save')
        ->assertHasFormErrors(['title' => 'required', 'storage_location_id' => 'required']);
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

/** A 1024-dimension unit vector pointing along the given axis. */
function axisVector(int $index): array
{
    $vector = array_fill(0, 1024, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}

test('the search field above the list finds items by keyword and by meaning', function () {
    $keyword = Item::factory()->create(['title' => 'Kunststoffgehäuse']);
    $semantic = Item::factory()->create(['title' => 'Pi-Hülle']);
    $semantic->forceFill(['embedding' => axisVector(1)])->saveQuietly();
    $other = Item::factory()->create(['title' => 'Gartenschlauch']);
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn () => axisVector(1), $prompt->inputs));

    Livewire::test(ListItems::class)
        ->searchTable('Gehäuse')
        ->assertCanSeeTableRecords([$keyword, $semantic], inOrder: true)
        ->assertCanNotSeeTableRecords([$other]);
});
