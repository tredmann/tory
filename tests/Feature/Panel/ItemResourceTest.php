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
use Illuminate\Http\UploadedFile;
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

test('the edit page shows the photos field with the first photo as overview', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);
    $url = parse_url(Storage::disk('local')->temporaryUrl('imports/a.jpg', now()->addMinutes(5)), PHP_URL_PATH);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldVisible('photo_edits')
        ->assertFormSet(['photo_edits.overview' => 'imports/a.jpg'])
        ->assertSee('Fotos')
        ->assertSeeHtml($url);
});

test('the photos field renders real buttons and the add tile', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertSeeHtml('x-data="itemPhotos(')
        ->assertSeeHtml('wire:ignore')
        ->assertSee('Foto hinzufügen')
        ->assertSee('Foto entfernen')
        ->assertSee('Hochladen abbrechen')
        ->assertSeeHtml('toryPhotoUploads');
});

test('the photos field also shows on an item with one photo', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldVisible('photo_edits');
});

test('saving a chosen overview photo moves it to the front and keeps the others in order', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['photo_edits.overview' => 'imports/c.jpg'])
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

test('saving an item with one photo keeps the photo', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('an item without photos can be edited', function () {
    $item = Item::factory()->create(['photos' => []]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->photos->toBe([]);
});

test('a path that is not a photo of the item falls back to the first remaining photo', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['photo_edits.overview' => 'imports/other.jpg'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg']);
});

test('choosing an overview photo does not recompute the embedding', function () {
    $embedding = array_fill(0, 1024, 0.1);
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg'], 'embedding' => $embedding]);
    Queue::fake();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['photo_edits.overview' => 'imports/b.jpg'])
        ->call('save')
        ->assertHasNoFormErrors();

    Queue::assertNotPushed(EmbedItem::class);
    expect($item->fresh()->embedding)->not->toBeNull();
});

test('saving passes added, removed and chosen photos to the item', function () {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    Storage::disk('local')->put('imports/b.jpg', 'b');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->image('new.jpg'))
        ->set('data.photo_edits.removed', ['imports/a.jpg'])
        ->set('data.photo_edits.overview', 'k')
        ->call('save')
        ->assertHasNoFormErrors();

    $photos = $item->fresh()->photos;

    expect($photos)->toHaveCount(2)
        ->and($photos[1])->toBe('imports/b.jpg')
        ->and($photos[0])->not->toBe('imports/a.jpg');
    Storage::disk('local')->assertExists($photos[0]);
    Storage::disk('local')->assertMissing('imports/a.jpg');
});

test('title and photos change in the same save', function () {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    Storage::disk('local')->put('imports/b.jpg', 'b');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->set('data.photo_edits.removed', ['imports/a.jpg'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->photos->toBe(['imports/b.jpg']);
});

test('an invalid title changes no photos', function () {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    Storage::disk('local')->put('imports/b.jpg', 'b');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => ''])
        ->set('data.photo_edits.removed', ['imports/a.jpg'])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->image('new.jpg'))
        ->call('save')
        ->assertHasFormErrors(['title']);

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg']);
    Storage::disk('local')->assertExists('imports/a.jpg');
    expect(Storage::disk('local')->allFiles('imports'))->toHaveCount(2);
});

test('saving twice stores an added photo once', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->image('new.jpg'))
        ->call('save')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toHaveCount(2)
        ->and(Storage::disk('local')->allFiles('imports'))->toHaveCount(1);
});

test('a file of the wrong type is rejected', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->create('notes.jpg', 10, 'text/plain'))
        ->call('save')
        ->assertHasFormErrors(['photo_edits']);

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

/** Livewire's own upload limit (12288 KB, config livewire.temporary_file_upload.rules) rejects it before the field's rule runs. */
test('a file over 12 MB is rejected', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->createWithContent('big.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7').str_repeat("\0", 12 * 1024 * 1024)))
        ->assertHasErrors(['data.photo_edits.uploads.k'])
        ->call('save');

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('a non-file value in the uploads state is ignored', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', 'imports/other.jpg')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('a photo uploaded through the Livewire upload lands under its key and is saved', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->upload('data.photo_edits.uploads.11111111-1111-4111-8111-111111111111', [UploadedFile::fake()->image('new.jpg')])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toHaveCount(2)
        ->and($item->fresh()->photos[0])->toBe('imports/a.jpg');
});

test('edits cleared by a restarted field neither delete a photo nor add an upload', function () {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    $page = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.removed', ['imports/a.jpg'])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->image('hidden.jpg'));

    $item->fresh()->update(['photos' => ['imports/a.jpg', 'imports/other-tab.jpg']]);

    $page->set('data.photo_edits.overview', 'imports/a.jpg')
        ->set('data.photo_edits.removed', [])
        ->set('data.photo_edits.uploads', [])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/other-tab.jpg'])
        ->and(Storage::disk('local')->exists('imports/a.jpg'))->toBeTrue();
});

test('a new photo marked for removal is not stored and does not block the save', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.fine', UploadedFile::fake()->image('new.jpg'))
        ->set('data.photo_edits.uploads.bad', UploadedFile::fake()->create('notes.jpg', 10, 'text/plain'))
        ->set('data.photo_edits.removed', ['fine', 'bad'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg'])
        ->and(Storage::disk('local')->files('imports'))->toBe([]);
});

test('a successful save tells the photos field to start over', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->call('save')
        ->assertDispatched('item-photos-saved');
});

test('saving with no photo left shows the error on the field', function () {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.removed', ['imports/a.jpg'])
        ->call('save')
        ->assertHasErrors(['data.photo_edits'])
        ->assertSee('Mindestens ein Foto.');

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('a save during a regeneration changes no photos', function () {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    Storage::disk('local')->put('imports/b.jpg', 'b');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    $page = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()]);
    $item->forceFill(['regenerating_at' => now()])->save();

    $page->set('data.photo_edits.removed', ['imports/a.jpg'])->call('save');

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg']);
    Storage::disk('local')->assertExists('imports/a.jpg');
});
