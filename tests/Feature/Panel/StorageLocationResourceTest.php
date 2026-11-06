<?php

use App\Filament\Resources\StorageLocations\Pages\CreateStorageLocation;
use App\Filament\Resources\StorageLocations\Pages\EditStorageLocation;
use App\Filament\Resources\StorageLocations\Pages\ListStorageLocations;
use App\Filament\Resources\StorageLocations\Pages\ViewStorageLocation;
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

test('the view page shows number, name and note', function () {
    $location = StorageLocation::factory()->create(['name' => 'Schrank', 'note' => 'Flur']);

    Livewire::test(ViewStorageLocation::class, ['record' => $location->getRouteKey()])
        ->assertSee("Nr. {$location->id}")->assertSee('Schrank')->assertSee('Flur');
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

    $this->get("/lagerorte/{$location->id}")->assertRedirect('/login');
    expect(session('url.intended'))->toEndWith("/lagerorte/{$location->id}");
});

test('the edit page offers no delete action', function () {
    $location = StorageLocation::factory()->create();

    Livewire::test(EditStorageLocation::class, ['record' => $location->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});
