<?php

use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('the list shows locations with their number of spots', function (): void {
    [$first, $second] = Location::factory()->count(2)->sequence(['name' => 'Garage'], ['name' => 'Keller'])->create();
    Spot::factory()->count(3)->create(['location_id' => $second->id]);

    $this->get('/locations')->assertOk();

    Livewire::test(ManageLocations::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertTableColumnStateSet('spots_count', 0, $first)
        ->assertTableColumnStateSet('spots_count', 3, $second);
});

test('a location is created', function (): void {
    Livewire::test(ManageLocations::class)
        ->callAction('create', ['name' => 'Keller'])
        ->assertHasNoFormErrors();

    expect(Location::query()->sole()->name)->toBe('Keller');
});

test('a location is renamed', function (): void {
    $location = Location::factory()->create(['name' => 'Keller']);

    Livewire::test(ManageLocations::class)
        ->callTableAction('edit', $location, ['name' => 'Dachboden'])
        ->assertHasNoFormErrors();

    expect($location->fresh()->name)->toBe('Dachboden');
});

test('a name that already exists is rejected', function (): void {
    Location::factory()->create(['name' => 'Keller']);
    $other = Location::factory()->create(['name' => 'Garage']);

    Livewire::test(ManageLocations::class)
        ->callAction('create', ['name' => 'Keller'])
        ->assertHasFormErrors(['name' => 'unique']);

    Livewire::test(ManageLocations::class)
        ->callTableAction('edit', $other, ['name' => 'Keller'])
        ->assertHasFormErrors(['name' => 'unique']);
});

test('a location can keep its own name when renamed', function (): void {
    $location = Location::factory()->create(['name' => 'Keller']);

    Livewire::test(ManageLocations::class)
        ->callTableAction('edit', $location, ['name' => 'Keller'])
        ->assertHasNoFormErrors();
});

test('delete is disabled while spots belong to the location', function (): void {
    $spot = Spot::factory()->create();

    Livewire::test(ManageLocations::class)
        ->assertTableActionDisabled('delete', $spot->location);
});

test('an empty location is deleted', function (): void {
    $location = Location::factory()->create();

    Livewire::test(ManageLocations::class)
        ->callTableAction('delete', $location);

    expect(Location::query()->count())->toBe(0);
});
