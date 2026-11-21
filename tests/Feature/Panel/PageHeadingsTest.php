<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Resources\Imports\Pages\CreateImport;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Filament\Resources\Spots\Pages\CreateSpot;
use App\Filament\Resources\Spots\Pages\EditSpot;
use App\Filament\Resources\Spots\Pages\ListSpots;
use App\Filament\Resources\Spots\Pages\ViewSpot;
use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * @param  class-string  $page
 * @param  array<string, mixed>  $parameters
 * @param  list<string>  $breadcrumbs
 */
function assertHeading(string $page, array $parameters, string $title, array $breadcrumbs): void
{
    $instance = Livewire::test($page, $parameters)->instance();
    $actual = $instance->getBreadcrumbs();

    expect($instance->getTitle())->toBe($title)
        ->and(array_key_first($actual))->toBe(Dashboard::getUrl())
        ->and((string) array_first($actual))->toContain('Übersicht')
        ->and(array_values(array_slice($actual, 1)))->toBe($breadcrumbs);
}

test('an item page is headed by the item title alone', function (): void {
    $item = Item::factory()->create(['title' => 'Bohrmaschine']);

    assertHeading(ViewItem::class, ['record' => $item->getKey()], 'Bohrmaschine', ['Gegenstände', 'Bohrmaschine']);
});

test('a spot page is headed by code and name alone', function (): void {
    $spot = Spot::factory()->create(['name' => 'Werkzeugkiste']);

    assertHeading(ViewSpot::class, ['record' => $spot->code], "{$spot->code} · Werkzeugkiste", ['Lagerplätze', "{$spot->code} · Werkzeugkiste"]);
});

test('an import page is headed by the import title once there is one', function (): void {
    $import = Import::factory()->create(['title' => 'Akkuschrauber']);

    assertHeading(ViewImport::class, ['record' => $import->getKey()], 'Akkuschrauber', ['Importe', 'Akkuschrauber']);
});

test('an import page without a title is headed by the word import', function (): void {
    $import = Import::factory()->create(['title' => null]);

    assertHeading(ViewImport::class, ['record' => $import->getKey()], 'Import', ['Importe', 'Import']);
});

test('a list only names itself in the breadcrumb', function (string $page, string $name): void {
    assertHeading($page, [], $name, [$name]);
})->with([
    'items' => [ListItems::class, 'Gegenstände'],
    'spots' => [ListSpots::class, 'Lagerplätze'],
    'imports' => [ListImports::class, 'Importe'],
    'locations' => [ManageLocations::class, 'Lagerorte'],
    'ai providers' => [ManageAiProviders::class, 'Eigene KI'],
]);

test('edit pages keep saying what happens, in german', function (): void {
    $item = Item::factory()->create(['title' => 'Bohrmaschine']);
    $spot = Spot::factory()->create(['name' => 'Werkzeugkiste']);

    assertHeading(EditItem::class, ['record' => $item->getKey()], 'Bohrmaschine bearbeiten', ['Gegenstände', 'Bohrmaschine', 'Bearbeiten']);
    assertHeading(EditSpot::class, ['record' => $spot->code], "{$spot->code} · Werkzeugkiste bearbeiten", ['Lagerplätze', "{$spot->code} · Werkzeugkiste", 'Bearbeiten']);
});

test('create pages keep saying what happens, in german', function (): void {
    assertHeading(CreateSpot::class, [], 'Lagerplatz erstellen', ['Lagerplätze', 'Erstellen']);
    assertHeading(CreateImport::class, [], 'Erfassen', ['Importe', 'Erfassen']);
});

test('the dashboard has no breadcrumb', function (): void {
    expect(Livewire::test(Dashboard::class)->instance()->getBreadcrumbs())->toBe([]);
});

test('the house is drawn as a link to the dashboard with a text for screen readers', function (): void {
    $this->withoutVite();

    $this->get('/items')
        ->assertOk()
        ->assertSeeInOrder([
            'href="'.Dashboard::getUrl().'"',
            '<svg',
            '<span class="tory-visually-hidden">Übersicht</span>',
            'Gegenstände',
        ], false)
        ->assertSee('.tory-visually-hidden { position: absolute;', false);
});

test('a rendered page does not carry the old labels', function (): void {
    $this->withoutVite();
    $item = Item::factory()->create(['title' => 'Bohrmaschine']);

    $this->get("/items/{$item->getKey()}")
        ->assertOk()
        ->assertSeeText('Bohrmaschine')
        ->assertDontSeeText('Ansehen')
        ->assertDontSeeText('View');
});

test('the login page speaks german', function (): void {
    auth()->logout();
    $this->withoutVite();

    $this->get('/login')->assertOk()->assertSeeText('Anmelden');
});

test('form errors speak german', function (): void {
    $errors = Livewire::test(CreateSpot::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->errors();

    expect($errors->first('data.name'))->toBe('Das Feld Name ist erforderlich.');
});

test('dates and times are shown the german way', function (): void {
    $import = Import::factory()->create(['created_at' => '2026-11-07 08:30:00']);

    Livewire::test(ListImports::class)->assertSeeText('07.11.2026 08:30');
    Livewire::test(ViewImport::class, ['record' => $import->getKey()])->assertSeeText('07.11.2026 08:30');
});
