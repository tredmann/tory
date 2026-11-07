<?php

use App\Enums\BulkImportStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\BulkImports\Pages\CreateBulkImport;
use App\Filament\Resources\BulkImports\Pages\ViewBulkImport;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\GroupBulkImport;
use App\Models\BulkImport;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->spot = Spot::factory()->create();
});

/**
 * Puts a file and its meta data into the field state as the browser does.
 */
function stagePhoto(Testable $component, string $key, mixed $file, mixed $name, mixed $takenAt = null): Testable
{
    return $component
        ->set("data.photos.uploads.{$key}", $file)
        ->set("data.photos.meta.{$key}", ['name' => $name, 'taken_at' => $takenAt]);
}

test('the upload page creates a bulk import and dispatches grouping', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'k1', UploadedFile::fake()->image('late.jpg'), 'late.jpg', '2026-11-07T10:05:00.000Z');
    stagePhoto($component, 'k2', UploadedFile::fake()->image('early.jpg'), 'early.jpg', '2026-11-07T10:00:00.000Z');

    $component->call('create')->assertHasNoFormErrors();

    $bulkImport = BulkImport::sole();

    expect($bulkImport->status)->toBe(BulkImportStatus::Grouping)
        ->and($bulkImport->spot_id)->toBe($this->spot->id)
        ->and($bulkImport->user_id)->toBe($this->user->id)
        ->and(array_column($bulkImport->photos, 'name'))->toBe(['early.jpg', 'late.jpg'])
        ->and($bulkImport->photos[0]['taken_at'])->toBe('2026-11-07T10:00:00+00:00')
        ->and(Storage::disk('local')->allFiles('imports'))->toHaveCount(2)
        ->and($this->user->fresh()->last_spot_id)->toBe($this->spot->id);

    $component->assertRedirect(BulkImportResource::getUrl('view', ['record' => $bulkImport]))
        ->assertNotified('Bulkimport angelegt');
    Queue::assertPushed(GroupBulkImport::class);
});

test('the spot is preselected with the last spot', function () {
    $this->user->forceFill(['last_spot_id' => $this->spot->id])->save();

    Livewire::test(CreateBulkImport::class)->assertFormSet(['spot_id' => $this->spot->id]);
});

test('photos without taken_at are ordered by name after timed ones', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'k1', UploadedFile::fake()->image('b.jpg'), 'b.jpg');
    stagePhoto($component, 'k2', UploadedFile::fake()->image('a.jpg'), 'a.jpg');
    stagePhoto($component, 'k3', UploadedFile::fake()->image('c.jpg'), 'c.jpg', '2026-11-07T10:00:00.000Z');

    $component->call('create')->assertHasNoFormErrors();

    expect(array_column(BulkImport::sole()->photos, 'name'))->toBe(['c.jpg', 'a.jpg', 'b.jpg']);
});

test('a wrong file type is rejected', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'k', UploadedFile::fake()->create('notes.jpg', 10, 'text/plain'), 'notes.jpg');

    $component->call('create')
        ->assertHasFormErrors(['photos' => 'Nur JPEG-, PNG-, WebP- oder GIF-Bilder bis 12 MB sind erlaubt.']);

    expect(BulkImport::count())->toBe(0);
    Queue::assertNothingPushed();
});

/** Livewire's own upload limit (12288 KB) rejects it before the field's rule runs. */
test('a file over 12 MB is rejected', function () {
    $big = UploadedFile::fake()->createWithContent('big.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7').str_repeat("\0", 12 * 1024 * 1024));

    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'k', $big, 'big.gif')
        ->assertHasErrors(['data.photos.uploads.k'])
        ->call('create');

    expect(BulkImport::count())->toBe(0);
});

test('more than 100 photos are rejected', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);

    foreach (range(1, 101) as $i) {
        stagePhoto($component, "k{$i}", UploadedFile::fake()->image("p{$i}.jpg", 1, 1), "p{$i}.jpg");
    }

    $component->call('create')
        ->assertHasFormErrors(['photos' => 'Höchstens 100 Fotos pro Bulkimport.']);

    expect(BulkImport::count())->toBe(0);
});

test('a forged non-file upload value is ignored', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'k', 'imports/other.jpg', 'other.jpg');

    $component->call('create')->assertHasFormErrors(['photos' => 'Mindestens ein Foto.']);
    expect(BulkImport::count())->toBe(0);

    stagePhoto($component, 'real', UploadedFile::fake()->image('real.jpg'), 'real.jpg');
    $component->call('create')->assertHasNoFormErrors();

    expect(array_column(BulkImport::sole()->photos, 'name'))->toBe(['real.jpg']);
});

test('an upload the browser no longer lists in meta is ignored', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'kept', UploadedFile::fake()->image('kept.jpg'), 'kept.jpg');
    $component->set('data.photos.uploads.removed', UploadedFile::fake()->image('removed.jpg'));

    $component->call('create')->assertHasNoFormErrors();

    expect(array_column(BulkImport::sole()->photos, 'name'))->toBe(['kept.jpg']);
});

test('an invalid taken_at is treated as missing', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'k1', UploadedFile::fake()->image('a.jpg'), 'a.jpg', 'tomorrow');
    stagePhoto($component, 'k2', UploadedFile::fake()->image('b.jpg'), 'b.jpg', '2026-13-45T99:00:00.000Z');
    stagePhoto($component, 'k3', UploadedFile::fake()->image('c.jpg'), 'c.jpg', ['2026-11-07']);

    $component->call('create')->assertHasNoFormErrors();

    expect(array_column(BulkImport::sole()->photos, 'taken_at'))->toBe([null, null, null]);
});

test('a missing or overlong name falls back to the file name and is cut to 255 characters', function () {
    $component = Livewire::test(CreateBulkImport::class)->fillForm(['spot_id' => $this->spot->id]);
    stagePhoto($component, 'k1', UploadedFile::fake()->image('upload.jpg'), null);
    stagePhoto($component, 'k2', UploadedFile::fake()->image('x.jpg'), str_repeat('n', 300));

    $component->call('create')->assertHasNoFormErrors();

    expect(array_column(BulkImport::sole()->photos, 'name'))->toBe([str_repeat('n', 255), 'upload.jpg']);
});

test('the resource has no navigation entry', function () {
    expect(BulkImportResource::shouldRegisterNavigation())->toBeFalse();
});

test('the upload page is titled Viele erfassen below the imports', function () {
    $this->get('/bulk-imports/create')->assertOk()->assertSee('Viele erfassen')->assertSee('Gruppieren')
        ->assertSee('\\u0022maxPhotos\\u0022:'.BulkImport::MAX_PHOTOS.'}', false);

    $page = Livewire::test(CreateBulkImport::class)->instance();
    $breadcrumbs = $page->getBreadcrumbs();

    expect($page->getTitle())->toBe('Viele erfassen')
        ->and(array_keys($breadcrumbs))->toBe([Dashboard::getUrl(), ImportResource::getUrl('index'), 0])
        ->and(array_values(array_slice($breadcrumbs, 1)))->toBe(['Importe', 'Viele erfassen']);
});

test('the bulk import page is titled Bulkimport below the imports', function () {
    $bulkImport = BulkImport::factory()->create();

    $page = Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getKey()])->instance();
    $breadcrumbs = $page->getBreadcrumbs();

    expect($page->getTitle())->toBe('Bulkimport')
        ->and(array_keys($breadcrumbs))->toBe([Dashboard::getUrl(), ImportResource::getUrl('index')])
        ->and(array_values(array_slice($breadcrumbs, 1)))->toBe(['Importe']);
});
