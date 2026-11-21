<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\ProviderFailure;
use App\Enums\AiVendor;
use App\Enums\ImportStatus;
use App\Enums\Language;
use App\Enums\ProviderFailureReason;
use App\Jobs\ExtractImport;
use App\Models\AiProvider;
use App\Models\HouseholdSettings;
use App\Models\Import;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/a.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
    Storage::disk('local')->put('imports/b.jpg', Storage::disk('local')->get('imports/a.jpg'));
});

test('declares one attempt and a 200 second timeout', function () {
    $job = new ExtractImport(Import::factory()->create());

    expect($job->tries)->toBe(1)->and($job->timeout)->toBe(200);
});

test('writes title and description and completes the import', function () {
    InventoryExtractor::fake([['title' => 'Hammer & Meißel "Profi"', 'description' => 'Aufdruck "Raspberry Pi 4 Model B".']]);
    $import = Import::factory()->create();

    (new ExtractImport($import))->handle();

    expect($import->fresh())
        ->status->toBe(ImportStatus::Completed)
        ->title->toBe('Hammer & Meißel "Profi"')
        ->description->toBe('Aufdruck "Raspberry Pi 4 Model B".')
        ->error->toBeNull();
    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2);
});

test('marks the import running before prompting', function () {
    $import = Import::factory()->create();
    InventoryExtractor::fake(function () use ($import) {
        expect($import->fresh()->status)->toBe(ImportStatus::Running);

        return ['title' => 'T', 'description' => 'D'];
    });

    (new ExtractImport($import))->handle();
});

test('reports and rethrows when the agent throws, and failed() records the error', function () {
    Exceptions::fake();
    InventoryExtractor::fake(fn () => throw new RuntimeException('Connection refused'));
    $import = Import::factory()->completed()->create();
    $job = new ExtractImport($import);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Connection refused');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Connection refused');

    $job->failed(new RuntimeException('Connection refused'));

    expect($import->fresh())
        ->status->toBe(ImportStatus::Failed)
        ->error->toBe('Connection refused')
        ->title->toBeNull()
        ->description->toBeNull();
});

test('failed() without an exception stores a fallback message', function () {
    $import = Import::factory()->running()->create();

    (new ExtractImport($import))->failed(null);

    expect($import->fresh())->status->toBe(ImportStatus::Failed)->error->not->toBeEmpty();
});

test('an incomplete answer throws the incomplete-answer message', function () {
    InventoryExtractor::fake([['title' => 'Nur ein Titel']]);

    expect(fn () => (new ExtractImport(Import::factory()->create()))->handle())
        ->toThrow(RuntimeException::class, 'Das Modell hat keine vollständige Antwort geliefert.');
});

test('a non-string description is treated as incomplete', function () {
    InventoryExtractor::fake([['title' => 'X', 'description' => ['a', 'b']]]);
    $import = Import::factory()->create();

    expect(fn () => (new ExtractImport($import))->handle())->toThrow(RuntimeException::class);
    expect($import->fresh())->title->toBeNull()->description->toBeNull();
});

test('a missing photo file fails instead of prompting', function () {
    InventoryExtractor::fake();
    $import = Import::factory()->create(['photos' => ['imports/missing.jpg']]);

    expect(fn () => (new ExtractImport($import))->handle())->toThrow(RuntimeException::class, 'Foto imports/missing.jpg wurde nicht gefunden.');
    InventoryExtractor::assertNeverPrompted();
});

test('the database queue retry_after exceeds the job timeout', function () {
    $job = new ExtractImport(Import::factory()->create());

    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});

test('runs on the active provider', function () {
    InventoryExtractor::fake([['title' => 'A', 'description' => 'B']]);
    AiProvider::factory()->active()->create(['vendor' => AiVendor::OpenAi, 'model' => 'gpt-x']);

    (new ExtractImport(Import::factory()->create()))->handle();

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->provider->driver() === 'openai' && $prompt->model === 'gpt-x');
});

test('reads the active provider fresh for every job', function () {
    InventoryExtractor::fake([['title' => 'A', 'description' => 'B'], ['title' => 'A', 'description' => 'B']]);
    $first = AiProvider::factory()->active()->create(['vendor' => AiVendor::OpenAi, 'model' => 'gpt-x']);
    $second = AiProvider::factory()->create(['vendor' => AiVendor::Mistral, 'model' => 'mis-x']);

    (new ExtractImport(Import::factory()->create()))->handle();
    $second->activate();
    (new ExtractImport(Import::factory()->create()))->handle();

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->model === 'gpt-x');
    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->model === 'mis-x' && $prompt->provider->driver() === 'mistral');
});

test('falls back to the inventory config without an active provider', function () {
    config(['ai.inventory.provider' => 'ollama']);
    InventoryExtractor::fake([['title' => 'A', 'description' => 'B']]);
    AiProvider::factory()->create(['vendor' => AiVendor::OpenAi]);

    (new ExtractImport(Import::factory()->create()))->handle();

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->provider->driver() === 'ollama');
});

test('stores reason and provider name for a fixable failure', function () {
    Exceptions::fake();
    Http::fake(['*' => Http::response([], 401)]);
    AiProvider::factory()->active()->create(['name' => 'Claude schnell']);
    $import = Import::factory()->create();
    $job = new ExtractImport($import);

    try {
        $job->handle();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(ProviderFailure::class);
        (new ExtractImport($import))->failed($e);
    }

    expect($import->fresh())
        ->status->toBe(ImportStatus::Failed)
        ->failure_reason->toBe(ProviderFailureReason::KeyRejected)
        ->failure_provider->toBe('Claude schnell');
});

test('a vendor error echoing the key does not leak it', function () {
    Exceptions::fake();
    Http::fake(['*' => Http::response(['error' => ['message' => 'bad key sk-secret-3fa9']], 401)]);
    AiProvider::factory()->active()->create(['api_key' => 'sk-secret-3fa9']);
    $job = new ExtractImport($import = Import::factory()->create());

    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }

    expect($import->fresh()->error)->not->toContain('sk-secret');
    Exceptions::assertReported(fn (ProviderFailure $e) => ! str_contains($e->getMessage(), 'sk-secret') && $e->getPrevious() === null);
});

test('a failure does not change the active provider', function () {
    Exceptions::fake();
    Http::fake(['*' => Http::response([], 401)]);
    $active = AiProvider::factory()->active()->create();
    $other = AiProvider::factory()->create();

    try {
        (new ExtractImport(Import::factory()->create()))->handle();
    } catch (Throwable) {
    }

    expect($active->fresh()->is_active)->toBeTrue()->and($other->fresh()->is_active)->toBeFalse();
});

test('without an active provider a failure stays unclassified', function () {
    Exceptions::fake();
    InventoryExtractor::fake(fn () => throw new RuntimeException('Connection refused'));
    $import = Import::factory()->create();
    $job = new ExtractImport($import);

    try {
        $job->handle();
    } catch (Throwable $e) {
        expect($e)->not->toBeInstanceOf(ProviderFailure::class);
        $job->failed($e);
    }

    expect($import->fresh())->failure_reason->toBeNull()->failure_provider->toBeNull()->error->toBe('Connection refused');
});

test('a success clears earlier failure details', function () {
    InventoryExtractor::fake([['title' => 'A', 'description' => 'B']]);
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::KeyRejected, 'Claude')->create();

    (new ExtractImport($import))->handle();

    expect($import->fresh())->failure_reason->toBeNull()->failure_provider->toBeNull();
});

test('a long key echoed past the exception truncation does not leak', function () {
    Exceptions::fake();
    $key = 'sk-proj-'.str_repeat('Ab3dE6gH9k', 16);
    Http::fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key provided: '.$key]], 401)]);
    AiProvider::factory()->active()->create(['api_key' => $key]);
    $job = new ExtractImport($import = Import::factory()->create());

    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }

    expect($import->fresh()->error)->not->toContain(substr($key, 0, 20));
    Exceptions::assertReported(fn (ProviderFailure $e) => ! str_contains($e->getMessage(), substr($key, 0, 20)));
});

test('stores the search terms', function () {
    InventoryExtractor::fake([['title' => 'A', 'description' => 'B', 'search_terms' => ['Hefter']]]);
    $import = Import::factory()->create();

    (new ExtractImport($import))->handle();

    expect($import->fresh()->search_terms)->toBe(['Hefter']);
});

test('failed clears the search terms', function () {
    $import = Import::factory()->completed()->create(['search_terms' => ['Hefter']]);

    (new ExtractImport($import))->failed(new RuntimeException('x'));

    expect($import->fresh()->search_terms)->toBe([]);
});

test('writes the texts in the household content language', function () {
    HouseholdSettings::current()->changeContentLanguage(Language::English);
    InventoryExtractor::fake([['title' => 'Stapler', 'description' => 'A red stapler.']]);

    (new ExtractImport(Import::factory()->create()))->handle();

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->agent->language === Language::English
        && $prompt->prompt === 'Analyze the photos and provide a title, a description and search terms.');
});
