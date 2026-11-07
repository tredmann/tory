<?php

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\CreateImport;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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
    $this->get('/imports/create')->assertOk()->assertSee('Erfassen');
});

test('unverified users are redirected to the verification prompt', function () {
    auth()->logout();
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/imports/create')->assertRedirect('/email-verification/prompt');
});

test('guests are redirected to login', function () {
    auth()->logout();

    $this->get('/imports/create')->assertRedirect('/login');
});

test('submitting without images fails validation and dispatches nothing', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [], 'spot_id' => Spot::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);

    Queue::assertNothingPushed();
    expect(Import::count())->toBe(0);
});

test('rejects a non-image file', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('notes.jpg', 10, 'text/plain')], 'spot_id' => Spot::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('rejects image formats the provider cannot read', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('photo.heic', 10, 'image/heic')], 'spot_id' => Spot::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('erfassen accepts twenty photos and rejects twenty-one', function () {
    $make = fn (int $n) => collect(range(1, $n))->map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"))->all();
    $spot = Spot::factory()->create();

    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => $make(21), 'spot_id' => $spot->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);

    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => $make(20), 'spot_id' => $spot->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Import::sole()->photos)->toHaveCount(20);
});

test('a valid upload stores the photos, creates a pending import, dispatches the job and redirects to the view page', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')], 'spot_id' => Spot::factory()->create()->id])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ImportResource::getUrl('view', ['record' => Import::first()]));

    $import = Import::sole();

    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->user_id)->toBe($this->user->id)
        ->and($import->photos)->toHaveCount(2)
        ->and($import->title)->toBeNull();

    foreach ($import->photos as $path) {
        expect($path)->toMatch('#^photos/\d{4}/\d{2}/[^/]+$#');
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
        ->fillForm(['photos' => ['livewire-tmp/secret.jpg'], 'spot_id' => Spot::factory()->create()->id])
        ->call('create')
        ->assertHasFormErrors(['photos']);

    expect(Import::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a spot is required', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('a.jpg')], 'spot_id' => null])
        ->call('create')
        ->assertHasFormErrors(['spot_id' => 'required']);
});

test('the chosen spot is stored on the import and remembered for the user', function () {
    $spot = Spot::factory()->create();

    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('a.jpg')], 'spot_id' => $spot->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Import::sole()->spot_id)->toBe($spot->id)
        ->and($this->user->fresh()->last_spot_id)->toBe($spot->id);
});

test('the last used spot is preselected', function () {
    $spot = Spot::factory()->create();
    $this->user->forceFill(['last_spot_id' => $spot->id])->save();

    Livewire::test(CreateImport::class)->assertFormSet(['spot_id' => $spot->id]);
});

test('nothing is preselected after the last used spot was deleted', function () {
    $spot = Spot::factory()->create();
    $this->user->forceFill(['last_spot_id' => $spot->id])->save();
    $spot->delete();
    $this->actingAs($this->user->fresh());

    Livewire::test(CreateImport::class)->assertFormSet(['spot_id' => null]);
});

test('a location can be created inside the spot picker', function () {
    Livewire::test(CreateImport::class)
        ->callAction([
            TestAction::make('createOption')->schemaComponent('spot_id'),
            TestAction::make('createOption')->schemaComponent('location_id', schema: 'mountedActionSchema0'),
        ], ['name' => 'Keller'])
        ->assertHasNoFormErrors();

    expect(Location::firstWhere('name', 'Keller'))->not->toBeNull();
});

test('a spot can be created through the picker', function () {
    $location = Location::factory()->create();

    $component = Livewire::test(CreateImport::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('spot_id'), ['name' => 'Regal'])
        ->assertHasNoFormErrors();

    $spot = Spot::firstWhere('name', 'Regal');

    expect($spot)->not->toBeNull()->and($spot->location->is($location))->toBeTrue();
    $component->assertFormSet(['spot_id' => $spot->id]);
});
