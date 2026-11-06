<?php

use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Filament\Resources\Spots\Pages\ViewSpot;
use App\Filament\Resources\Spots\RelationManagers\ItemsRelationManager;
use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

dataset('lists', ['items list', 'imports list', 'spot items list']);
dataset('view pages', ['item view page', 'import view page']);
dataset('all surfaces', ['items list', 'imports list', 'spot items list', 'item view page', 'import view page']);

/**
 * Renders one of the five surfaces for a new record with the given photos.
 *
 * @param  array<string>  $photos
 */
function photoSurface(string $surface, array $photos): Testable
{
    if ($surface === 'items list') {
        Item::factory()->create(['photos' => $photos]);

        return Livewire::test(ListItems::class);
    }

    if ($surface === 'imports list') {
        Import::factory()->create(['photos' => $photos]);

        return Livewire::test(ListImports::class);
    }

    return match ($surface) {
        'spot items list' => Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => Item::factory()->create(['photos' => $photos])->spot,
            'pageClass' => ViewSpot::class,
        ]),
        'item view page' => Livewire::test(ViewItem::class, ['record' => Item::factory()->create(['photos' => $photos])->getKey()]),
        'import view page' => Livewire::test(ViewImport::class, ['record' => Import::factory()->create(['photos' => $photos])->getKey()]),
    };
}

/**
 * Reads the slideshow data the way a browser does: from the parsed attributes.
 *
 * @return array<int, array{urls: mixed, index: int}>
 */
function slideshowImages(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    $images = [];

    foreach ((new DOMXPath($document))->query('//img[@data-slideshow]') as $image) {
        $images[] = [
            'urls' => json_decode($image->getAttribute('data-slideshow'), true),
            'index' => (int) $image->getAttribute('data-slideshow-index'),
        ];
    }

    return $images;
}

/**
 * @param  array<string>  $photos
 */
function storePhotos(array $photos): void
{
    foreach ($photos as $photo) {
        Storage::disk('local')->put($photo, 'photo');
    }
}

test('a list thumbnail opens all photos of its record from the first', function (string $surface) {
    $photos = ['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg'];
    storePhotos($photos);

    $images = slideshowImages(photoSurface($surface, $photos)->html());

    expect($images)->toHaveCount(1);
    expect($images[0]['index'])->toBe(0);
    expect($images[0]['urls'])->toHaveCount(3);
    expect($images[0]['urls'][0])->toContain('a.jpg');
    expect($images[0]['urls'][1])->toContain('b.jpg');
    expect($images[0]['urls'][2])->toContain('c.jpg');
})->with('lists');

test('each view page thumbnail starts at its own photo', function (string $surface) {
    $photos = ['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg'];
    storePhotos($photos);

    $images = slideshowImages(photoSurface($surface, $photos)->html());

    expect(array_column($images, 'index'))->toBe([0, 1, 2]);
    expect($images[2]['urls'])->toHaveCount(3);
    expect($images[2]['urls'][2])->toContain('c.jpg');
})->with('view pages');

test('a photo whose file is missing is left out and the positions match the rest', function () {
    $photos = ['imports/missing.jpg', 'imports/b.jpg', 'imports/c.jpg'];
    storePhotos(['imports/b.jpg', 'imports/c.jpg']);

    $images = slideshowImages(photoSurface('item view page', $photos)->html());

    expect(array_column($images, 'index'))->toBe([0, 1]);
    expect($images[1]['urls'])->toHaveCount(2);
    expect($images[1]['urls'][1])->toContain('c.jpg');
});

test('a list thumbnail whose file is missing opens no slideshow', function (string $surface) {
    storePhotos(['imports/b.jpg']);

    expect(slideshowImages(photoSurface($surface, ['imports/missing.jpg', 'imports/b.jpg'])->html()))->toBeEmpty();
})->with('lists');

test('a path with special characters stays a valid attribute', function (string $surface) {
    $photos = ['imports/a "b" & c.jpg'];
    storePhotos($photos);

    $images = slideshowImages(photoSurface($surface, $photos)->html());

    expect($images)->toHaveCount(1);
    expect($images[0]['urls'])->toBeArray()->toHaveCount(1);
})->with('all surfaces');

test('a record without photos renders without slideshow data', function (string $surface) {
    expect(slideshowImages(photoSurface($surface, [])->assertOk()->html()))->toBeEmpty();
})->with('all surfaces');

test('view page thumbnails are large and side by side', function (string $surface) {
    storePhotos(['imports/a.jpg']);

    photoSurface($surface, ['imports/a.jpg'])
        ->assertSeeHtml('height: 160px')
        ->assertSeeHtml('fi-wrapped')
        ->assertDontSeeHtml('fi-stacked');
})->with('view pages');

test('list thumbnails are large', function (string $surface) {
    storePhotos(['imports/a.jpg']);

    photoSurface($surface, ['imports/a.jpg'])->assertSeeHtml('height: 80px');
})->with('lists');

test('list rows still link to the detail page', function () {
    $item = Item::factory()->create();
    $import = Import::factory()->create();
    $spot = Spot::factory()->create();
    $itemOnSpot = Item::factory()->create(['spot_id' => $spot->id]);

    Livewire::test(ListItems::class)->assertSeeHtml(ItemResource::getUrl('view', ['record' => $item]));
    Livewire::test(ListImports::class)->assertSeeHtml(ImportResource::getUrl('view', ['record' => $import]));
    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $spot, 'pageClass' => ViewSpot::class])
        ->assertSeeHtml(ItemResource::getUrl('view', ['record' => $itemOnSpot]));
});

test('the import view page keeps the slideshow data on a re-render', function () {
    storePhotos(['imports/a.jpg']);
    $import = Import::factory()->running()->create(['photos' => ['imports/a.jpg']]);

    $page = Livewire::test(ViewImport::class, ['record' => $import->getKey()])->call('$refresh');

    expect(slideshowImages($page->html()))->toHaveCount(1);
});
