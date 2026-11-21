<?php

use App\Enums\Language;
use App\Filament\Resources\Spots\Pages\CreateSpot;
use App\Filament\Resources\Spots\Pages\EditSpot;
use App\Filament\Resources\Spots\Pages\ListSpots;
use App\Filament\Resources\Spots\Pages\ViewSpot;
use App\Filament\Resources\Spots\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Spots\Schemas\SpotSelect;
use App\Models\BulkImport;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

test('the list shows spots by number with their item counts', function () {
    [$first, $second] = Spot::factory()->count(2)->create();
    Item::factory()->count(3)->create(['spot_id' => $second->id]);

    Livewire::test(ListSpots::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertTableColumnStateSet('items_count', 0, $first)
        ->assertTableColumnStateSet('items_count', 3, $second);
});

test('a spot is created with name and note', function () {
    $location = Location::factory()->create();

    Livewire::test(CreateSpot::class)
        ->fillForm(['name' => 'Elektronik-Kiste', 'note' => 'Keller, Regal links', 'location_id' => $location->getKey()])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Spot::sole())->name->toBe('Elektronik-Kiste')->note->toBe('Keller, Regal links');
});

test('the name is required', function () {
    Livewire::test(CreateSpot::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Spot::count())->toBe(0);
});

test('name and note can be edited', function () {
    $spot = Spot::factory()->create();

    Livewire::test(EditSpot::class, ['record' => $spot->getRouteKey()])
        ->fillForm(['name' => 'Schrank', 'note' => 'Flur'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($spot->fresh())->name->toBe('Schrank')->note->toBe('Flur');
});

test('the list finds a spot by code and by name', function () {
    $wanted = Spot::factory()->create(['code' => '7KQ3', 'name' => 'Schrank']);
    $other = Spot::factory()->create(['code' => 'XY22', 'name' => 'Kiste']);

    Livewire::test(ListSpots::class)
        ->searchTable('7KQ3')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('Kiste')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$wanted]);
});

test('the view page names the spot in its title and its location below', function () {
    $location = Location::factory()->create(['name' => 'Keller']);
    $spot = Spot::factory()->for($location)->create(['name' => 'Schrank']);

    $page = Livewire::test(ViewSpot::class, ['record' => $spot->getRouteKey()])
        ->assertSee("{$spot->code} · Schrank");

    expect($page->instance()->getSubheading())->toBe('Keller');
});

test('the view page shows the note with its line breaks', function () {
    $spot = Spot::factory()->create(['note' => "Rote Kiste\nunterstes Fach"]);

    Livewire::test(ViewSpot::class, ['record' => $spot->getRouteKey()])
        ->assertSchemaComponentVisible('note', 'infolist')
        ->assertSeeHtml('Rote Kiste<br');
});

test('the view page leaves the note out when there is none and shows no form instead', function () {
    $spot = Spot::factory()->create(['note' => null]);

    Livewire::test(ViewSpot::class, ['record' => $spot->getRouteKey()])
        ->assertSchemaComponentHidden('note', 'infolist')
        ->assertDontSee('Notiz');
});

test('a spot opens by its code in any case', function () {
    $spot = Spot::factory()->create(['code' => '7KQ3']);

    $this->get('/spots/7KQ3')->assertOk()->assertSee($spot->name);
    $this->get('/spots/7kq3')->assertOk()->assertSee($spot->name);
});

test('an empty spot can be deleted', function () {
    $spot = Spot::factory()->create();

    Livewire::test(ViewSpot::class, ['record' => $spot->getRouteKey()])
        ->callAction('delete');

    expect(Spot::count())->toBe(0);
});

test('a spot holding items is not deleted and says why', function () {
    $spot = Item::factory()->create()->spot;

    Livewire::test(ViewSpot::class, ['record' => $spot->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Der Lagerplatz enthält noch Gegenstände und kann nicht gelöscht werden.');

    expect($spot->fresh())->not->toBeNull();
});

test('a spot used by a bulk import is not deleted and says why', function () {
    $spot = Spot::factory()->create();
    BulkImport::factory()->grouping()->create(['spot_id' => $spot->id]);

    Livewire::test(ViewSpot::class, ['record' => $spot->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Dieser Lagerplatz wird noch von einem Bulkimport verwendet.');

    expect($spot->fresh())->not->toBeNull();
});

test('a guest opening a spot is sent to login and back', function () {
    auth()->logout();
    $spot = Spot::factory()->create();

    $this->get("/spots/{$spot->code}")->assertRedirect('/login');
    expect(session('url.intended'))->toEndWith("/spots/{$spot->code}");
});

test('the edit page offers no delete action', function () {
    $spot = Spot::factory()->create();

    Livewire::test(EditSpot::class, ['record' => $spot->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

test('the view page lists exactly the items inside the spot', function () {
    $spot = Spot::factory()->create();
    $inside = Item::factory()->count(2)->create(['spot_id' => $spot->id]);
    $elsewhere = Item::factory()->create();

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $spot, 'pageClass' => ViewSpot::class])
        ->assertCanSeeTableRecords($inside)
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

test('a spot requires a location', function () {
    Location::factory()->count(2)->create();

    Livewire::test(CreateSpot::class)
        ->fillForm(['name' => 'Kiste', 'location_id' => null])
        ->call('create')
        ->assertHasFormErrors(['location_id' => 'required']);

    expect(Spot::count())->toBe(0);
});

test('the only location is preselected', function () {
    $location = Location::factory()->create();

    Livewire::test(CreateSpot::class)
        ->assertFormSet(['location_id' => $location->getKey()]);

    Location::factory()->create();

    Livewire::test(CreateSpot::class)
        ->assertFormSet(['location_id' => null]);
});

test('moving a spot keeps its code and its items', function () {
    $item = Item::factory()->create();
    $spot = $item->spot;
    $code = $spot->code;
    $target = Location::factory()->create();

    Livewire::test(EditSpot::class, ['record' => $spot->getRouteKey()])
        ->fillForm(['location_id' => $target->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($spot->fresh()->code)->toBe($code)
        ->and($item->fresh()->spot->location->is($target))->toBeTrue();
});

test('a location can be created from the spot form', function () {
    $component = Livewire::test(CreateSpot::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('location_id'), ['name' => 'Keller'])
        ->assertHasNoFormErrors();

    $location = Location::firstWhere('name', 'Keller');

    expect($location)->not->toBeNull();
    $component->assertFormSet(['location_id' => $location->getKey()]);
});

test('the picker groups spots by location, sorted by name', function () {
    $keller = Location::factory()->create(['name' => 'Keller']);
    $garage = Location::factory()->create(['name' => 'Garage']);
    $regal = Spot::factory()->create(['name' => 'Regal', 'location_id' => $keller->id]);
    $kiste = Spot::factory()->create(['name' => 'Kiste', 'location_id' => $keller->id]);
    $werkbank = Spot::factory()->create(['name' => 'Werkbank', 'location_id' => $garage->id]);

    $options = SpotSelect::options();

    expect($options)->toBe([
        'Garage' => [$werkbank->id => $werkbank->label],
        'Keller' => [$kiste->id => $kiste->label, $regal->id => $regal->label],
    ])->and(array_keys($options))->toBe(['Garage', 'Keller']);
});

test('the picker finds spots by code, spot name or location name', function (string $term) {
    $keller = Location::factory()->create(['name' => 'Keller']);
    $garage = Location::factory()->create(['name' => 'Garage']);
    $regal = Spot::factory()->create(['name' => 'Regal', 'location_id' => $keller->id, 'code' => 'ABCD']);
    Spot::factory()->create(['name' => 'Werkbank', 'location_id' => $garage->id, 'code' => 'WXYZ']);

    expect(SpotSelect::options($term))->toBe(['Keller' => [$regal->id => $regal->label]]);
})->with(['abcd', 'regal', 'keller']);

test('every word of the picker search has to match', function () {
    $keller = Location::factory()->create(['name' => 'Keller']);
    $regal = Spot::factory()->create(['name' => 'Regal', 'location_id' => $keller->id]);

    expect(SpotSelect::options('keller regal'))->toBe(['Keller' => [$regal->id => $regal->label]])
        ->and(SpotSelect::options('keller werkbank'))->toBe([]);
});

test('percent and underscore are searched literally in the picker', function (string $term) {
    Spot::factory()->create(['name' => 'Regal']);

    expect(SpotSelect::options($term))->toBe([]);
})->with(['%', '_']);

test('the picker search treats wildcards as literal characters and still finds them', function () {
    $location = Location::factory()->create(['name' => 'Keller']);
    $underscore = Spot::factory()->create(['name' => 'a_b', 'location_id' => $location->id]);
    $other = Spot::factory()->create(['name' => 'aXb', 'location_id' => $location->id]);
    $backslash = Spot::factory()->create(['name' => 'a\\b', 'location_id' => $location->id]);

    expect(SpotSelect::options('_')['Keller'])->toHaveKey($underscore->id)->not->toHaveKey($other->id)
        ->and(SpotSelect::options('a_b')['Keller'])->toHaveKey($underscore->id)->not->toHaveKey($other->id)
        ->and(SpotSelect::options('\\')['Keller'])->toHaveKey($backslash->id)->not->toHaveKey($other->id);
});

test('the picker omits locations without spots', function () {
    Location::factory()->create(['name' => 'Leer']);
    $spot = Spot::factory()->create();

    expect(array_keys(SpotSelect::options()))->toBe([$spot->location->name]);
});

test('creating a spot rejects a duplicate location name inside the picker form', function () {
    Location::factory()->create(['name' => 'Keller']);

    Livewire::test(CreateSpot::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('location_id'), ['name' => 'Keller'])
        ->assertHasFormErrors(['name']);

    expect(Location::where('name', 'Keller')->count())->toBe(1);
});

test('a new location may share its name with a spot', function () {
    Spot::factory()->create(['name' => 'Keller']);

    Livewire::test(CreateSpot::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('location_id'), ['name' => 'Keller'])
        ->assertHasNoFormErrors();

    expect(Location::where('name', 'Keller')->count())->toBe(1);
});

test('a new location from the edit page may share its name with another spot', function () {
    Spot::factory()->create(['name' => 'Keller']);
    $spot = Spot::factory()->create();

    Livewire::test(EditSpot::class, ['record' => $spot->getRouteKey()])
        ->callAction(TestAction::make('createOption')->schemaComponent('location_id'), ['name' => 'Keller'])
        ->assertHasNoFormErrors();

    expect(Location::where('name', 'Keller')->count())->toBe(1);
});

test('the items of a spot show where they lie, like every item tile', function () {
    $spot = Spot::factory()->create(['name' => 'Regal 3', 'location_id' => Location::factory()->create(['name' => 'Keller'])->id]);
    $item = Item::factory()->create(['spot_id' => $spot->id, 'title' => 'Bohrmaschine']);

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $spot, 'pageClass' => ViewSpot::class])
        ->assertCanSeeTableRecords([$item])
        ->assertSeeText('Bohrmaschine')
        ->assertSeeText($spot->code)
        ->assertSeeText('Keller · Regal 3');
});

test('an English user sees the spot list in English', function () {
    $user = User::factory()->create();
    $user->forceFill(['locale' => Language::English])->save();
    app()->setLocale('en');
    $this->actingAs($user);

    Livewire::test(ListSpots::class)
        ->assertSee('Spots')
        ->assertSee('Location')
        ->assertDontSee('Lagerplätze')
        ->assertDontSee('Lagerort');
});
