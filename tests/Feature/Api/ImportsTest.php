<?php

use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('uploads photos and starts extraction', function () {
    $spot = Spot::factory()->create();

    $response = $this->postJson('/api/v1/imports', [
        'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        'spot' => strtolower($spot->code),
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.spot.code', $spot->code)
        ->assertJsonCount(2, 'data.photos');

    $import = Import::findOrFail($response->json('data.id'));
    expect($import->photos)->toHaveCount(2)
        ->and($import->user_id)->toBe($this->user->id)
        ->and($import->spot_id)->toBe($spot->id)
        ->and($this->user->fresh()->last_spot_id)->toBe($spot->id);
    foreach ($import->photos as $path) {
        Storage::disk('local')->assertExists($path);
        expect($path)->toStartWith('imports/');
    }
    Queue::assertPushed(ExtractImport::class);
});

it('rejects invalid uploads', function (Closure $payload, string $field) {
    $spot = Spot::factory()->create();

    $this->postJson('/api/v1/imports', $payload($spot))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Import::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'seven photos' => [fn (Spot $s) => ['spot' => $s->code, 'photos' => array_map(fn ($i) => UploadedFile::fake()->image("$i.jpg"), range(1, 7))], 'photos'],
    'a pdf' => [fn (Spot $s) => ['spot' => $s->code, 'photos' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]], 'photos.0'],
    'a 13 MB file' => [fn (Spot $s) => ['spot' => $s->code, 'photos' => [UploadedFile::fake()->create('big.jpg', 13 * 1024, 'image/jpeg')]], 'photos.0'],
    'no spot' => [fn (Spot $s) => ['photos' => [UploadedFile::fake()->image('a.jpg')]], 'spot'],
    'unknown spot' => [fn (Spot $s) => ['spot' => 'ZZZZ', 'photos' => [UploadedFile::fake()->image('a.jpg')]], 'spot'],
]);

it('lists imports newest first with 24 per page', function () {
    $old = Import::factory()->create(['created_at' => now()->subDay()]);
    Import::factory()->count(24)->create();
    $newest = Import::factory()->create(['created_at' => now()->addDay()]);

    $this->getJson('/api/v1/imports')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 24)
        ->assertJsonPath('meta.total', 26)
        ->assertJsonPath('data.0.id', $newest->id);
    expect($old)->not->toBeNull();
});

it('shows an import with the classified failure message and hides the raw error without debug', function () {
    config(['app.debug' => false]);
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::KeyRejected, 'Claude')->create();

    $this->getJson("/api/v1/imports/{$import->id}")
        ->assertOk()
        ->assertJsonPath('data.failure_message', $import->failureMessage())
        ->assertJsonPath('data.error', null)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.status_label', 'Fehlgeschlagen')
        ->assertJsonMissingPath('data.spot_id')
        ->assertJsonMissingPath('data.user_id');
});

it('uses the general failure message for an unclassified failure', function () {
    $import = Import::factory()->failed()->create();

    $this->getJson("/api/v1/imports/{$import->id}")
        ->assertJsonPath('data.failure_message', 'Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.');
});

it('shows the raw error with debug', function () {
    config(['app.debug' => true]);
    $import = Import::factory()->failed()->create();

    $this->getJson("/api/v1/imports/{$import->id}")->assertJsonPath('data.error', 'Connection refused');
});

it('retries a failed import', function () {
    $import = Import::factory()->failed()->create();

    $this->postJson("/api/v1/imports/{$import->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
    expect($import->fresh()->status)->toBe(ImportStatus::Pending);
    Queue::assertPushed(ExtractImport::class);
});

it('answers a conflict when retrying an import that has not failed', function () {
    $import = Import::factory()->completed()->create();

    $this->postJson("/api/v1/imports/{$import->id}/retry")
        ->assertConflict()
        ->assertJsonPath('message', 'Nur fehlgeschlagene Importe lassen sich erneut starten.');
    Queue::assertNothingPushed();
});

it('converts an import into an item', function () {
    Storage::disk('local')->put('imports/a.jpg', 'x');
    $import = Import::factory()->completed()->create(['photos' => ['imports/a.jpg']]);
    $spot = Spot::factory()->create();

    $this->postJson("/api/v1/imports/{$import->id}/convert", [
        'title' => 'Pi',
        'description' => 'Platine',
        'search_terms' => ['rechner'],
        'spot' => $spot->code,
    ])->assertCreated()
        ->assertJsonPath('data.title', 'Pi')
        ->assertJsonPath('data.spot.code', $spot->code)
        ->assertJsonPath('data.search_terms', ['rechner'])
        ->assertJsonMissingPath('data.embedding')
        ->assertJsonMissingPath('data.spot_id');

    expect(Import::find($import->id))->toBeNull()
        ->and($this->user->fresh()->last_spot_id)->toBe($spot->id);
    Storage::disk('local')->assertExists('imports/a.jpg');
});

it('answers 404 on a second convert and creates nothing', function () {
    $import = Import::factory()->completed()->create();
    $spot = Spot::factory()->create();
    $payload = ['title' => 'Pi', 'spot' => $spot->code];

    $this->postJson("/api/v1/imports/{$import->id}/convert", $payload)->assertCreated();
    $this->postJson("/api/v1/imports/{$import->id}/convert", $payload)->assertNotFound();

    expect(Item::count())->toBe(1);
});

it('validates the convert payload', function () {
    $import = Import::factory()->completed()->create();

    $this->postJson("/api/v1/imports/{$import->id}/convert", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'spot']);
});

it('answers a conflict when converting a running or regenerating import', function (string $state) {
    $import = Import::factory()->completed()->create();
    if ($state === 'running') {
        $import->update(['status' => ImportStatus::Running]);
    } else {
        $import->forceFill(['regenerating_at' => now()])->save();
    }
    $spot = Spot::factory()->create();

    $this->postJson("/api/v1/imports/{$import->id}/convert", ['title' => 'Pi', 'spot' => $spot->code])
        ->assertConflict()
        ->assertJsonPath('message', 'Der Import lässt sich gerade nicht übernehmen.');
    expect(Item::count())->toBe(0);
})->with(['running', 'regenerating']);

it('deletes a finished import with its photos', function () {
    Storage::disk('local')->put('imports/a.jpg', 'x');
    $import = Import::factory()->failed()->create(['photos' => ['imports/a.jpg']]);

    $this->deleteJson("/api/v1/imports/{$import->id}")->assertNoContent();

    expect(Import::find($import->id))->toBeNull();
    Storage::disk('local')->assertMissing('imports/a.jpg');
});

it('answers a conflict when deleting a pending import', function () {
    $import = Import::factory()->pending()->create();

    $this->deleteJson("/api/v1/imports/{$import->id}")
        ->assertConflict()
        ->assertJsonPath('message', 'Der Import läuft noch.');
    expect(Import::find($import->id))->not->toBeNull();
});
