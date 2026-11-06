<?php

use App\Filament\Resources\StorageLocations\Pages\CreateStorageLocation;
use App\Filament\Resources\StorageLocations\Pages\EditStorageLocation;
use App\Filament\Resources\StorageLocations\Pages\ListStorageLocations;
use App\Filament\Resources\StorageLocations\Pages\ViewStorageLocation;
use App\Filament\Resources\StorageLocations\RelationManagers\ItemsRelationManager;
use App\Models\Item;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

test('the list shows locations by number with their item counts', function () {
    [$first, $second] = StorageLocation::factory()->count(2)->create();
    Item::factory()->count(3)->create(['storage_location_id' => $second->id]);

    Livewire::test(ListStorageLocations::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertTableColumnStateSet('items_count', 0, $first)
        ->assertTableColumnStateSet('items_count', 3, $second);
});

test('a location is created with name and note', function () {
    Livewire::test(CreateStorageLocation::class)
        ->fillForm(['name' => 'Elektronik-Kiste', 'note' => 'Keller, Regal links'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StorageLocation::sole())->name->toBe('Elektronik-Kiste')->note->toBe('Keller, Regal links');
});

test('the name is required', function () {
    Livewire::test(CreateStorageLocation::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(StorageLocation::count())->toBe(0);
});

test('name and note can be edited', function () {
    $location = StorageLocation::factory()->create();

    Livewire::test(EditStorageLocation::class, ['record' => $location->getRouteKey()])
        ->fillForm(['name' => 'Schrank', 'note' => 'Flur'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($location->fresh())->name->toBe('Schrank')->note->toBe('Flur');
});

test('the list finds a location by code and by name', function () {
    $wanted = StorageLocation::factory()->create(['code' => '7KQ3', 'name' => 'Schrank']);
    $other = StorageLocation::factory()->create(['code' => 'XY22', 'name' => 'Kiste']);

    Livewire::test(ListStorageLocations::class)
        ->searchTable('7KQ3')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('Kiste')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$wanted]);
});

test('the view page shows code, name and note', function () {
    $location = StorageLocation::factory()->create(['name' => 'Schrank', 'note' => 'Flur']);

    Livewire::test(ViewStorageLocation::class, ['record' => $location->getRouteKey()])
        ->assertSee($location->code)->assertSee('Schrank')->assertSee('Flur');
});

test('a location opens by its code in any case', function () {
    $location = StorageLocation::factory()->create(['code' => '7KQ3']);

    $this->get('/lagerorte/7KQ3')->assertOk()->assertSee($location->name);
    $this->get('/lagerorte/7kq3')->assertOk()->assertSee($location->name);
});

test('an empty location can be deleted', function () {
    $location = StorageLocation::factory()->create();

    Livewire::test(ViewStorageLocation::class, ['record' => $location->getRouteKey()])
        ->callAction('delete');

    expect(StorageLocation::count())->toBe(0);
});

test('a location holding items is not deleted and says why', function () {
    $location = Item::factory()->create()->storageLocation;

    Livewire::test(ViewStorageLocation::class, ['record' => $location->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Der Lagerort enthält noch Gegenstände und kann nicht gelöscht werden.');

    expect($location->fresh())->not->toBeNull();
});

test('a guest opening a location is sent to login and back', function () {
    auth()->logout();
    $location = StorageLocation::factory()->create();

    $this->get("/lagerorte/{$location->code}")->assertRedirect('/login');
    expect(session('url.intended'))->toEndWith("/lagerorte/{$location->code}");
});

test('the edit page offers no delete action', function () {
    $location = StorageLocation::factory()->create();

    Livewire::test(EditStorageLocation::class, ['record' => $location->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

test('the view page lists exactly the items inside the location', function () {
    $location = StorageLocation::factory()->create();
    $inside = Item::factory()->count(2)->create(['storage_location_id' => $location->id]);
    $elsewhere = Item::factory()->create();

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $location, 'pageClass' => ViewStorageLocation::class])
        ->assertCanSeeTableRecords($inside)
        ->assertCanNotSeeTableRecords([$elsewhere]);
});
