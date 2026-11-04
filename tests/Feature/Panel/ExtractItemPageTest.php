<?php

use App\Ai\Agents\InventoryExtractor;
use App\Filament\Pages\ExtractItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('the page is reachable for verified users', function () {
    $this->get('/erfassen')->assertOk()->assertSee('Erfassen');
});

test('unverified users are redirected to the verification prompt', function () {
    auth()->logout();
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/erfassen')->assertRedirect('/email-verification/prompt');
});

test('guests are redirected to login', function () {
    auth()->logout();

    $this->get('/erfassen')->assertRedirect('/login');
});

test('submitting without images fails validation and never calls the agent', function () {
    InventoryExtractor::fake();

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => []])
        ->call('analyze')
        ->assertHasFormErrors(['photos']);

    InventoryExtractor::assertNeverPrompted();
});

test('rejects a non-image file', function () {
    InventoryExtractor::fake();

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('notes.jpg', 10, 'text/plain')]])
        ->call('analyze')
        ->assertHasFormErrors(['photos']);

    InventoryExtractor::assertNeverPrompted();
});

test('rejects more than six images', function () {
    InventoryExtractor::fake();

    $photos = collect(range(1, 7))->map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"))->all();

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => $photos])
        ->call('analyze')
        ->assertHasFormErrors(['photos']);

    InventoryExtractor::assertNeverPrompted();
});

test('shows the extracted title and description for the uploaded images', function () {
    InventoryExtractor::fake([
        ['title' => 'Hammer & Meißel "Profi"', 'description' => 'Grüne Platine, vier USB-Anschlüsse, Aufdruck "Raspberry Pi 4 Model B".'],
    ]);

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')]])
        ->call('analyze')
        ->assertHasNoFormErrors()
        ->assertSet('extractedTitle', 'Hammer & Meißel "Profi"')
        ->assertSee('Hammer & Meißel "Profi"')
        ->assertSet('data.photos', [])
        ->assertSee('Aufdruck "Raspberry Pi 4 Model B"');

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2);
});

test('shows an error notification when the agent throws', function () {
    Exceptions::fake();
    InventoryExtractor::fake(fn () => throw new RuntimeException('Connection refused'));

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg')]])
        ->call('analyze')
        ->assertNotified('Analyse fehlgeschlagen')
        ->assertSet('extractedTitle', null)
        ->assertSet('extractedDescription', null)
        ->assertSet('data.photos', []);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Connection refused');
});

test('treats an incomplete structured answer as a failure', function () {
    InventoryExtractor::fake([['title' => 'Nur ein Titel']]);

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg')]])
        ->call('analyze')
        ->assertNotified('Analyse fehlgeschlagen')
        ->assertSet('extractedTitle', null);
});

test('treats a non-string description as a failure without leaving a partial result', function () {
    Exceptions::fake();
    InventoryExtractor::fake([['title' => 'X', 'description' => ['a', 'b']]]);

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg')]])
        ->call('analyze')
        ->assertNotified('Analyse fehlgeschlagen')
        ->assertSet('extractedTitle', null)
        ->assertSet('extractedDescription', null);
});

test('rejects image formats the provider cannot read', function () {
    InventoryExtractor::fake();

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('photo.heic', 10, 'image/heic')]])
        ->call('analyze')
        ->assertHasFormErrors(['photos']);

    InventoryExtractor::assertNeverPrompted();
});
