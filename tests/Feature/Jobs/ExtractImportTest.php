<?php

use App\Ai\Agents\InventoryExtractor;
use App\Enums\AiVendor;
use App\Enums\ImportStatus;
use App\Jobs\ExtractImport;
use App\Models\AiProvider;
use App\Models\Import;
use Illuminate\Support\Facades\Exceptions;
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
