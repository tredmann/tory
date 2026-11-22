<?php

use App\Enums\Language;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Jobs\EmbedItem;
use App\Models\Import;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use App\Photos\PhotoLinks;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

test('the list shows items newest first with their spot', function (): void {
    $spot = Spot::factory()->create(['name' => 'Elektronik-Kiste']);
    $old = Item::factory()->create(['spot_id' => $spot->id, 'created_at' => now()->subDay()]);
    $new = Item::factory()->create(['spot_id' => $spot->id]);

    Livewire::test(ListItems::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->assertSee($spot->code)
        ->assertSee('· Elektronik-Kiste')
        ->assertSeeHtml("/spots/{$spot->code}");
});

test('the list can still be sorted by date', function (): void {
    $old = Item::factory()->create(['created_at' => now()->subDay()]);
    $new = Item::factory()->create();

    Livewire::test(ListItems::class)
        ->sortTable('created_at', 'asc')
        ->assertCanSeeTableRecords([$old, $new], inOrder: true)
        ->assertSeeText('Angelegt');
});

test('the list pages in full rows of tiles', function (): void {
    $items = collect(range(1, 13))->map(fn (int $age): Item => Item::factory()->create(['created_at' => now()->subDays($age)]));

    Livewire::test(ListItems::class)
        ->assertCanSeeTableRecords($items->take(12)->all())
        ->assertCanNotSeeTableRecords([$items->last()]);
});

test('items cannot be created directly', function (): void {
    expect(ItemResource::canCreate())->toBeFalse()
        ->and(ItemResource::getPages())->not->toHaveKey('create');
});

test('the view page shows title, description and spot', function (): void {
    $spot = Spot::factory()->create(['name' => 'Schrank']);
    $item = Item::factory()->create([
        'spot_id' => $spot->id,
        'title' => 'Akkuschrauber',
        'description' => 'Gelb, mit Ladegerät',
    ]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Akkuschrauber')
        ->assertSee('Gelb, mit Ladegerät')
        ->assertSee($spot->code)
        ->assertSee('· Schrank')
        ->assertSeeHtml("/spots/{$spot->code}");
});

test('the view page shows the title only as its heading', function (): void {
    $item = Item::factory()->create(['title' => 'Akkuschrauber']);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Akkuschrauber')
        ->assertDontSee('Titel');
});

test('the view page leaves out empty texts', function (): void {
    $item = Item::factory()->create(['description' => null, 'notes' => null, 'search_terms' => []]);

    $page = Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertDontSee('Beschreibung')
        ->assertDontSee('Bemerkung')
        ->assertDontSee('Suchbegriffe')
        ->assertDontSee('—');

    expect(preg_match_all('/<section\b/', $page->html()))->toBe(2);
});

test('the view page shows search terms', function (): void {
    $item = Item::factory()->create(['search_terms' => ['Akkubohrer', 'Werkzeug']]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Suchbegriffe')
        ->assertSee('Akkubohrer')
        ->assertSee('Werkzeug');
});

test('the edit page removes a search term', function (): void {
    $item = Item::factory()->create(['search_terms' => ['A', 'B']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['search_terms' => ['A']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->search_terms)->toBe(['A']);
});

test('editing changes the title and moves the item to another spot', function (): void {
    $item = Item::factory()->create();
    $target = Spot::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel', 'spot_id' => $target->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->spot_id->toBe($target->id);
});

test('title and spot are required when editing', function (): void {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => '', 'spot_id' => null])
        ->call('save')
        ->assertHasFormErrors(['title' => 'required', 'spot_id' => 'required']);
});

test('the edit page offers no delete action', function (): void {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

test('deleting an item removes the row and its photo files', function (): void {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $item = Item::factory()->create(['photos' => ['imports/x.jpg']]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])->callAction('delete');

    expect(Item::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('imports/x.jpg');
});

test('the search field above the list finds items by keyword and by meaning', function (): void {
    activeSearchProvider();
    $keyword = Item::factory()->create(['title' => 'Kunststoffgehäuse']);
    $semantic = Item::factory()->create(['title' => 'Pi-Hülle', 'created_at' => now()->addMinute()]);
    $semantic->forceFill(['embedding' => axis(1)])->saveQuietly();
    $other = Item::factory()->create(['title' => 'Gartenschlauch']);
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => axis(1), $prompt->inputs));

    Livewire::test(ListItems::class)
        ->searchTable('Gehäuse')
        ->assertCanSeeTableRecords([$keyword, $semantic], inOrder: true)
        ->assertCanNotSeeTableRecords([$other]);
});

test('the list and view page show the location', function (): void {
    $spot = Spot::factory()->create([
        'location_id' => Location::factory()->create(['name' => 'Keller'])->id,
    ]);
    $item = Item::factory()->create(['spot_id' => $spot->id]);

    Livewire::test(ListItems::class)
        ->assertSee('Keller · ')
        ->assertSeeHtml("/spots/{$spot->code}");

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Keller · ')
        ->assertSeeHtml("/spots/{$spot->code}");
});

test('filters items by location', function (): void {
    $keller = Location::factory()->create(['name' => 'Keller']);
    $garage = Location::factory()->create(['name' => 'Garage']);
    $inKeller = Item::factory()->create(['spot_id' => Spot::factory()->create(['location_id' => $keller->id])->id]);
    $inGarage = Item::factory()->create(['spot_id' => Spot::factory()->create(['location_id' => $garage->id])->id]);

    Livewire::test(ListItems::class)
        ->filterTable('location', $keller->getKey())
        ->assertCanSeeTableRecords([$inKeller])
        ->assertCanNotSeeTableRecords([$inGarage]);
});

test('the location filter narrows a text search', function (): void {
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

test('a location can be created inside the spot picker on the edit page', function (): void {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->callAction([
            TestAction::make('createOption')->schemaComponent('spot_id'),
            TestAction::make('createOption')->schemaComponent('location_id', schema: 'mountedActionSchema0'),
        ], ['name' => 'Keller'])
        ->assertHasNoFormErrors();

    expect(Location::query()->firstWhere('name', 'Keller'))->not->toBeNull();
});

test('the edit page shows the photos field with the first photo as overview', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldVisible('photo_edits')
        ->assertFormSet(['photo_edits.overview' => 'imports/a.jpg'])
        ->assertSee('Fotos')
        ->assertSeeHtml(PhotoLinks::thumbnail('imports/a.jpg'));
});

test('the photos field renders real buttons and the add tile', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertSeeHtml('x-data="itemPhotos(')
        ->assertSeeHtml('\\u0022maxPhotos\\u0022:'.Import::MAX_PHOTOS.'}')
        ->assertSeeHtml('wire:ignore')
        ->assertSee('Foto hinzufügen')
        ->assertSee('Foto entfernen')
        ->assertSee('Hochladen abbrechen')
        ->assertSeeHtml('toryPhotoUploads');
});

test('the photos field also shows on an item with one photo', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertFormFieldVisible('photo_edits');
});

test('saving a chosen overview photo moves it to the front and keeps the others in order', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['photo_edits.overview' => 'imports/c.jpg'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/c.jpg', 'imports/a.jpg', 'imports/b.jpg']);
});

test('saving without choosing another photo keeps the order', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg', 'imports/c.jpg']);
});

test('saving an item with one photo keeps the photo', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('an item without photos can be edited', function (): void {
    $item = Item::factory()->create(['photos' => []]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->photos->toBe([]);
});

test('a path that is not a photo of the item falls back to the first remaining photo', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['photo_edits.overview' => 'imports/other.jpg'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg']);
});

test('choosing an overview photo does not recompute the embedding', function (): void {
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

test('saving passes added, removed and chosen photos to the item', function (): void {
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

test('title and photos change in the same save', function (): void {
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

test('an invalid title changes no photos', function (): void {
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

test('saving twice stores an added photo once', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->image('new.jpg'))
        ->call('save')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toHaveCount(2)
        ->and(Storage::disk('local')->allFiles('photos'))->toHaveCount(1);
});

test('a file of the wrong type is rejected', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->create('notes.jpg', 10, 'text/plain'))
        ->call('save')
        ->assertHasFormErrors(['photo_edits']);

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

/** Livewire's own upload limit (12288 KB, config livewire.temporary_file_upload.rules) rejects it before the field's rule runs. */
test('a file over 12 MB is rejected', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->createWithContent('big.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7').str_repeat("\0", 12 * 1024 * 1024)))
        ->assertHasErrors(['data.photo_edits.uploads.k'])
        ->call('save');

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('a non-file value in the uploads state is ignored', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', 'imports/other.jpg')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('a photo uploaded through the Livewire upload lands under its key and is saved', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->upload('data.photo_edits.uploads.11111111-1111-4111-8111-111111111111', [UploadedFile::fake()->image('new.jpg')])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toHaveCount(2)
        ->and($item->fresh()->photos[0])->toBe('imports/a.jpg');
});

test('edits cleared by a restarted field neither delete a photo nor add an upload', function (): void {
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

test('a new photo marked for removal is not stored and does not block the save', function (): void {
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

test('a successful save tells the photos field to start over', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->call('save')
        ->assertDispatched('item-photos-saved');
});

test('saving with no photo left shows the error on the field', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.removed', ['imports/a.jpg'])
        ->call('save')
        ->assertHasErrors(['data.photo_edits'])
        ->assertSee('Mindestens ein Foto.');

    expect($item->fresh()->photos)->toBe(['imports/a.jpg']);
});

test('a save during a regeneration changes no photos', function (): void {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    Storage::disk('local')->put('imports/b.jpg', 'b');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg', 'imports/b.jpg']]);

    $page = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()]);
    $item->forceFill(['regenerating_at' => now()])->save();

    $page->set('data.photo_edits.removed', ['imports/a.jpg'])->call('save');

    expect($item->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg']);
    Storage::disk('local')->assertExists('imports/a.jpg');
});

test('a failed write of an uploaded photo keeps the row and the old photo', function (): void {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    $page = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->image('new.jpg'))
        ->set('data.photo_edits.removed', ['imports/a.jpg']);

    breakLocalDisk();

    expect(fn () => $page->call('save'))->toThrow(RuntimeException::class);

    expect($item->fresh()->photos)->toBe(['imports/a.jpg'])
        ->and(Storage::disk('local')->files('imports'))->toBe(['imports/a.jpg']);
});

test('a failed write removes the uploaded photos stored before it', function (): void {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    $page = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k1', UploadedFile::fake()->image('one.jpg'))
        ->set('data.photo_edits.uploads.k2', UploadedFile::fake()->image('two.jpg'));

    breakLocalDisk(writesBeforeFailure: 1);

    expect(fn () => $page->call('save'))->toThrow(RuntimeException::class);

    expect($item->fresh()->photos)->toBe(['imports/a.jpg'])
        ->and(Storage::disk('local')->files('imports'))->toBe(['imports/a.jpg']);
});

test('a write that leaves a shorter file fails and removes that file', function (): void {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    $page = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.k', UploadedFile::fake()->image('new.jpg'))
        ->set('data.photo_edits.removed', ['imports/a.jpg']);

    breakLocalDisk(truncatesWrites: true);

    expect(fn () => $page->call('save'))->toThrow(RuntimeException::class);

    expect($item->fresh()->photos)->toBe(['imports/a.jpg'])
        ->and(Storage::disk('local')->files('imports'))->toBe(['imports/a.jpg']);
});

test('an upload marked by a numeric key is neither checked nor stored', function (): void {
    $item = Item::factory()->create(['photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->set('data.photo_edits.uploads.0', UploadedFile::fake()->create('page.html', 10, 'text/html'))
        ->set('data.photo_edits.removed', [0])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->photos)->toBe(['imports/a.jpg'])
        ->and(Storage::disk('local')->files('imports'))->toBe([]);
});

test('a rejected photo change leaves the title as it was', function (): void {
    Storage::disk('local')->put('imports/a.jpg', 'a');
    $item = Item::factory()->create(['title' => 'Alter Titel', 'photos' => ['imports/a.jpg']]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel'])
        ->set('data.photo_edits.removed', ['imports/a.jpg'])
        ->call('save')
        ->assertHasErrors(['data.photo_edits']);

    expect($item->fresh())->title->toBe('Alter Titel')->photos->toBe(['imports/a.jpg']);
    Storage::disk('local')->assertExists('imports/a.jpg');
});

test('the view page shows the note', function (): void {
    $item = Item::factory()->create(['notes' => 'Netzteil fehlt']);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Bemerkung')
        ->assertSee('Netzteil fehlt');
});

test('the edit page changes and clears the note', function (): void {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['notes' => 'Dongle fehlt'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($item->fresh()->notes)->toBe('Dongle fehlt');

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['notes' => ''])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($item->fresh()->notes)->toBeNull();
});

test('an English user sees the item list in English', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['locale' => Language::English])->save();
    app()->setLocale('en');
    $this->actingAs($user);

    Livewire::test(ListItems::class)
        ->assertSee('Items')
        ->assertSee('Search …')
        ->assertDontSee('Gegenstände')
        ->assertDontSee('Suchen …');
});

test('an English user sees the photo field of the edit page in English', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['locale' => Language::English])->save();
    app()->setLocale('en');
    $this->actingAs($user);
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Photos')
        ->assertDontSee('Fotos');
});

test('the item photo picker gets its messages in the language of the user', function (Language $language, string $tooMany, string $unreadable): void {
    $user = User::factory()->create();
    $user->forceFill(['locale' => $language])->save();
    app()->setLocale($language->value);
    $this->actingAs($user);
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertSee($tooMany, false)
        ->assertSee($unreadable, false);
})->with([
    'German' => [Language::German, 'Höchstens :max Fotos.', 'Dieses Bild lässt sich nicht lesen.'],
    'English' => [Language::English, 'At most :max photos.', 'This image cannot be read.'],
]);
