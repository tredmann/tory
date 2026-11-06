<?php

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\CreateImport;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the create page is reachable for verified users and titled Erfassen', function () {
    $this->get('/importe/create')->assertOk()->assertSee('Erfassen');
});

test('unverified users are redirected to the verification prompt', function () {
    auth()->logout();
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/importe/create')->assertRedirect('/email-verification/prompt');
});

test('guests are redirected to login', function () {
    auth()->logout();

    $this->get('/importe/create')->assertRedirect('/login');
});

test('submitting without images fails validation and dispatches nothing', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [], 'storage_location_id' => StorageLocation::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);

    Queue::assertNothingPushed();
    expect(Import::count())->toBe(0);
});

test('rejects a non-image file', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('notes.jpg', 10, 'text/plain')], 'storage_location_id' => StorageLocation::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('rejects image formats the provider cannot read', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('photo.heic', 10, 'image/heic')], 'storage_location_id' => StorageLocation::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('rejects more than six images', function () {
    $photos = collect(range(1, 7))->map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"))->all();

    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => $photos, 'storage_location_id' => StorageLocation::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('a valid upload stores the photos, creates a pending import, dispatches the job and redirects to the view page', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')], 'storage_location_id' => StorageLocation::factory()->create()->id])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ImportResource::getUrl('view', ['record' => Import::first()]));

    $import = Import::sole();

    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->user_id)->toBe($this->user->id)
        ->and($import->photos)->toHaveCount(2)
        ->and($import->title)->toBeNull();

    foreach ($import->photos as $path) {
        expect($path)->toStartWith('imports/');
        Storage::disk('local')->assertExists($path);
    }

    Queue::assertPushed(ExtractImport::class, fn (ExtractImport $job) => $job->import->is($import));
    Queue::assertPushed(ExtractImport::class, 1);
});

test('the old synchronous page is gone', function () {
    $this->get('/erfassen')->assertNotFound();
});

test('rejects photo paths outside the imports directory', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => ['livewire-tmp/secret.jpg'], 'storage_location_id' => StorageLocation::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);

    expect(Import::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a storage location is required', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('a.jpg')], 'storage_location_id' => null])
        ->call('create')
        ->assertHasFormErrors(['storage_location_id' => 'required']);
});

test('the chosen location is stored on the import and remembered for the user', function () {
    $location = StorageLocation::factory()->create();

    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('a.jpg')], 'storage_location_id' => $location->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Import::sole()->storage_location_id)->toBe($location->id)
        ->and($this->user->fresh()->last_storage_location_id)->toBe($location->id);
});

test('the last used location is preselected', function () {
    $location = StorageLocation::factory()->create();
    $this->user->forceFill(['last_storage_location_id' => $location->id])->save();

    Livewire::test(CreateImport::class)->assertFormSet(['storage_location_id' => $location->id]);
});

test('nothing is preselected after the last used location was deleted', function () {
    $location = StorageLocation::factory()->create();
    $this->user->forceFill(['last_storage_location_id' => $location->id])->save();
    $location->delete();
    $this->actingAs($this->user->fresh());

    Livewire::test(CreateImport::class)->assertFormSet(['storage_location_id' => null]);
});
