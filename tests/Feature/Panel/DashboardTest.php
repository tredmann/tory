<?php

use App\Enums\Language;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Widgets\OpenBulkImportsWidget;
use App\Filament\Widgets\OpenImportsWidget;
use App\Filament\Widgets\RecentItemsWidget;
use App\Models\BulkImport;
use App\Models\Import;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

test('the dashboard shows tory instead of the filament widgets', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSeeText('Übersicht')
        ->assertDontSeeText('Willkommen')
        ->assertDontSee('filamentphp.com');
});

test('the capture action leads to the import form', function (): void {
    Livewire::test(Dashboard::class)
        ->assertActionHasUrl('capture', ImportResource::getUrl('create'));
});

test('the search form leads to the item list', function (): void {
    $this->get('/')
        ->assertSee('action="'.ItemResource::getUrl('index').'"', false)
        ->assertSee('name="search"', false)
        ->assertSee('placeholder="Wo ist …?"', false);
});

test('the search parameter filters the item list', function (): void {
    $drill = Item::factory()->create(['title' => 'Bohrmaschine']);
    $tent = Item::factory()->create(['title' => 'Zelt']);

    Livewire::withQueryParams(['search' => 'Bohrmaschine'])
        ->test(ListItems::class)
        ->assertCanSeeTableRecords([$drill])
        ->assertCanNotSeeTableRecords([$tent]);
});

test('the recent list shows the latest eight items, newest first', function (): void {
    $items = collect(range(1, 9))->map(fn (int $age): Item => Item::factory()->create(['created_at' => now()->subDays($age)]));

    Livewire::test(RecentItemsWidget::class)
        ->assertCanSeeTableRecords($items->take(8)->all(), inOrder: true)
        ->assertCanNotSeeTableRecords([$items->last()])
        ->assertSeeText('9 Gegenstände');
});

test('a recent item shows its spot code and location', function (): void {
    $spot = Spot::factory()->for(Location::factory()->state(['name' => 'Keller']))->create();
    Item::factory()->for($spot)->create();

    Livewire::test(RecentItemsWidget::class)
        ->assertSeeText($spot->code)
        ->assertSeeText('Keller')
        ->assertSeeText('1 Gegenstand');
});

test('a recent photo opens the slideshow of its item', function (): void {
    Storage::disk('local')->put('imports/a.jpg', 'photo');
    Storage::disk('local')->put('imports/b.jpg', 'photo');
    Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(RecentItemsWidget::class)
        ->assertSeeHtml('data-slideshow=')
        ->assertSeeHtml('data-slideshow-index="0"');
});

test('a recent item without photos is still listed', function (): void {
    $item = Item::factory()->create(['title' => 'Altes Radio', 'photos' => []]);

    Livewire::test(RecentItemsWidget::class)
        ->assertCanSeeTableRecords([$item])
        ->assertSeeText('Altes Radio');
});

test('the recent list says so when nothing is captured yet', function (): void {
    Livewire::test(RecentItemsWidget::class)
        ->assertSeeText('Noch nichts erfasst');
});

test('open imports stay hidden while there are none', function (): void {
    $this->get('/')->assertDontSeeText('Offene Importe');
});

test('open imports show failed and completed imports', function (): void {
    $failed = Import::factory()->failed()->create();
    $completed = Import::factory()->completed()->create();

    $this->get('/')->assertSeeText('Offene Importe');

    Livewire::test(OpenImportsWidget::class)
        ->assertCanSeeTableRecords([$failed, $completed])
        ->assertSeeText('Fehlgeschlagen')
        ->assertSeeText('Fertig');
});

test('open imports link to all imports only when more exist than fit', function (): void {
    Import::factory()->count(5)->create();

    Livewire::test(OpenImportsWidget::class)->assertTableActionHidden('all');

    $oldest = Import::factory()->create(['created_at' => now()->subDay()]);

    Livewire::test(OpenImportsWidget::class)
        ->assertTableActionVisible('all')
        ->assertCanNotSeeTableRecords([$oldest]);
});

test('locations are ranked by item count, empty ones included', function (): void {
    $garage = Location::factory()->create(['name' => 'Garage']);
    $cellar = Location::factory()->create(['name' => 'Keller']);
    Location::factory()->create(['name' => 'Dachboden']);
    Item::factory()->for(Spot::factory()->for($garage))->create();
    Item::factory()->count(2)->for(Spot::factory()->for($cellar))->create();

    $this->get('/')
        ->assertSeeTextInOrder(['Lagerorte', 'Keller', '2', 'Garage', '1', 'Dachboden', '0']);
});

test('a location links to the item list filtered by it', function (): void {
    $cellar = Location::factory()->create(['name' => 'Keller']);
    $inCellar = Item::factory()->for(Spot::factory()->for($cellar))->create();
    $elsewhere = Item::factory()->create();

    $url = ItemResource::getUrl('index', ['filters' => ['location' => ['value' => $cellar->getKey()]]]);

    $this->get('/')->assertSee(e($url), false);

    Livewire::withQueryParams(['filters' => ['location' => ['value' => $cellar->getKey()]]])
        ->test(ListItems::class)
        ->assertCanSeeTableRecords([$inCellar])
        ->assertCanNotSeeTableRecords([$elsewhere]);
});

test('a location name is escaped', function (): void {
    Location::factory()->create(['name' => '<b>Keller</b>']);

    $this->get('/')
        ->assertDontSee('<b>Keller</b>', false)
        ->assertSee(e('<b>Keller</b>'), false);
});

test('the dashboard offers Viele erfassen', function (): void {
    Livewire::test(Dashboard::class)
        ->assertActionHasUrl('captureMany', BulkImportResource::getUrl('create'))
        ->assertActionHasLabel('captureMany', 'Viele erfassen');
});

test('the dashboard lists open bulk imports with their status', function (): void {
    $bulk = BulkImport::factory()->grouping()->create();

    $this->get('/')->assertSeeText('Offene Bulkimporte');

    Livewire::test(OpenBulkImportsWidget::class)
        ->assertCanSeeTableRecords([$bulk])
        ->assertSee('3 Fotos')
        ->assertSee($bulk->status->getLabel());
});

test('an open bulk import with one photo says "1 Foto"', function (): void {
    $bulk = BulkImport::factory()->grouping()->create();
    $bulk->update(['photos' => [$bulk->photos[0]]]);

    Livewire::test(OpenBulkImportsWidget::class)
        ->assertSee('1 Foto')
        ->assertDontSee('1 Fotos');
});

test('the dashboard hides open bulk imports when there are none', function (): void {
    $this->get('/')->assertDontSeeText('Offene Bulkimporte');
});

test('an English user sees the dashboard widgets in English', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['locale' => Language::English])->save();
    app()->setLocale('en');
    $this->actingAs($user);
    BulkImport::factory()->grouping()->create();
    Import::factory()->create();
    Location::factory()->create();

    $this->get('/')
        ->assertSee('placeholder="Where is …?"', false)
        ->assertSee('aria-label="Search items"', false)
        ->assertSeeText('Open bulk imports')
        ->assertSeeText('Open imports')
        ->assertSeeText('Recently added')
        ->assertSeeText('3 photos')
        ->assertDontSee('Wo ist')
        ->assertDontSeeText('Offene Importe');
});
