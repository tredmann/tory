<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\ProviderFailure;
use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Jobs\EmbedItem;
use App\Jobs\RegenerateTexts;
use App\Models\AiProvider;
use App\Models\Import;
use App\Models\Item;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/a.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
    $this->makeRecord = fn (string $kind) => $kind === 'item'
        ? Item::factory()->create(['title' => 'Alt', 'description' => 'Alte Beschreibung', 'search_terms' => ['alt'], 'photos' => ['imports/a.jpg'], 'regenerating_at' => now()])
        : Import::factory()->completed()->create(['title' => 'Alt', 'description' => 'Alte Beschreibung', 'search_terms' => ['alt'], 'photos' => ['imports/a.jpg'], 'regenerating_at' => now()]);
});

test('declares one attempt and a 200 second timeout', function () {
    $job = new RegenerateTexts(Item::factory()->create());

    expect($job->tries)->toBe(1)->and($job->timeout)->toBe(200)->and($job->deleteWhenMissingModels)->toBeTrue();
});

test('replaces title, description and terms and clears the running state', function (string $kind) {
    Queue::fake();
    InventoryExtractor::fake([['title' => 'Neu', 'description' => 'Neue Beschreibung', 'search_terms' => ['neu', 'frisch']]]);
    $record = ($this->makeRecord)($kind);
    $record->forceFill(['regeneration_error' => 'alt'])->saveQuietly();

    (new RegenerateTexts($record))->handle();

    expect($record->fresh())
        ->title->toBe('Neu')
        ->description->toBe('Neue Beschreibung')
        ->search_terms->toBe(['neu', 'frisch'])
        ->regenerating_at->toBeNull()
        ->regeneration_error->toBeNull()
        ->regeneration_failure_reason->toBeNull();
    if ($kind === 'import') {
        expect($record->fresh()->status)->toBe(ImportStatus::Completed);
    }
})->with(['item', 'import']);

test('a changed item text dispatches EmbedItem', function () {
    Queue::fake();
    InventoryExtractor::fake([['title' => 'Neu', 'description' => 'D', 'search_terms' => []]]);
    $item = ($this->makeRecord)('item');
    Queue::fake();

    (new RegenerateTexts($item))->handle();

    Queue::assertPushed(EmbedItem::class);
});

test('keeps the old texts when the extractor fails', function (string $kind) {
    Exceptions::fake();
    InventoryExtractor::fake(fn () => throw new RuntimeException('Connection refused'));
    $record = ($this->makeRecord)($kind);
    $job = new RegenerateTexts($record);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Connection refused');
    $job->failed(new RuntimeException('Connection refused'));

    expect($record->fresh())
        ->title->toBe('Alt')
        ->description->toBe('Alte Beschreibung')
        ->search_terms->toBe(['alt'])
        ->regenerating_at->toBeNull()
        ->regeneration_error->toBe('Connection refused');
})->with(['item', 'import']);

test('keeps the old texts when a photo is missing', function () {
    Exceptions::fake();
    InventoryExtractor::fake();
    $item = ($this->makeRecord)('item');
    $item->forceFill(['photos' => ['imports/missing.jpg']])->saveQuietly();
    $job = new RegenerateTexts($item);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'wurde nicht gefunden');
    $job->failed(new RuntimeException('Foto imports/missing.jpg wurde nicht gefunden.'));

    InventoryExtractor::assertNeverPrompted();
    expect($item->fresh())->title->toBe('Alt')->regenerating_at->toBeNull()->regeneration_error->not->toBeNull();
});

test('stores the failure reason and provider for an active provider', function () {
    Exceptions::fake();
    Http::fake(['*' => Http::response([], 401)]);
    AiProvider::factory()->active()->create(['name' => 'Claude schnell']);
    $import = ($this->makeRecord)('import');
    $job = new RegenerateTexts($import);

    try {
        $job->handle();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(ProviderFailure::class);
        $job->failed($e);
    }

    expect($import->fresh())
        ->status->toBe(ImportStatus::Completed)
        ->title->toBe('Alt')
        ->regeneration_failure_reason->toBe(ProviderFailureReason::KeyRejected)
        ->regeneration_failure_provider->toBe('Claude schnell');
});

test('failed without an exception clears the running state', function () {
    $item = ($this->makeRecord)('item');

    (new RegenerateTexts($item))->failed(null);

    expect($item->fresh())->regenerating_at->toBeNull()->regeneration_error->toBe('Der Auftrag wurde abgebrochen.');
});

test('a record deleted during the run ends quietly', function () {
    Queue::fake();
    $item = ($this->makeRecord)('item');
    InventoryExtractor::fake(function () use ($item) {
        Item::query()->whereKey($item->getKey())->delete();

        return ['title' => 'Neu', 'description' => 'D', 'search_terms' => []];
    });

    (new RegenerateTexts($item))->handle();

    expect(Item::count())->toBe(0);
});

test('failed on a deleted record does nothing', function () {
    $item = ($this->makeRecord)('item');
    Item::query()->whereKey($item->getKey())->delete();

    (new RegenerateTexts($item))->failed(new RuntimeException('x'));

    expect(Item::count())->toBe(0);
});
