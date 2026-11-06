<?php

use App\Ai\ProviderImportResult;
use App\Ai\ProviderTransfer;
use App\Enums\AiVendor;
use App\Jobs\ReembedItems;
use App\Models\AiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * @param  array<int, array<string, mixed>>  $providers
 */
function transferFile(array $providers): string
{
    return json_encode(['format' => 'tory-ai-providers', 'version' => 1, 'providers' => $providers], JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transferEntry(array $overrides = []): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'name' => 'OpenAI',
        'vendor' => 'openai',
        'api_key' => 'sk-file-key-1234',
        'url' => null,
        'model' => 'gpt-x',
        ...$overrides,
    ];
}

test('a round trip restores values and key, encrypted and with its hint', function () {
    $cloud = AiProvider::factory()->create(['name' => 'Claude', 'api_key' => 'sk-secret-3fa9', 'model' => 'claude-x']);
    $local = AiProvider::factory()->ollama()->create(['name' => 'Lokal']);
    $transfer = new ProviderTransfer;

    $json = $transfer->export()->toJson();
    DB::table('ai_providers')->delete();

    expect($transfer->import($json))->toEqual(new ProviderImportResult(created: 2, skipped: 0, renamed: 0));

    $restored = AiProvider::query()->whereKey($cloud->id)->firstOrFail();

    expect($restored->name)->toBe('Claude')
        ->and($restored->vendor)->toBe(AiVendor::Anthropic)
        ->and($restored->model)->toBe('claude-x')
        ->and($restored->url)->toBeNull()
        ->and($restored->api_key)->toBe('sk-secret-3fa9')
        ->and($restored->key_hint)->toBe('3fa9')
        ->and($restored->getRawOriginal('api_key'))->not->toContain('sk-secret');

    $restoredLocal = AiProvider::query()->whereKey($local->id)->firstOrFail();

    expect($restoredLocal->vendor)->toBe(AiVendor::Ollama)
        ->and($restoredLocal->url)->toBe($local->url)
        ->and($restoredLocal->model)->toBe($local->model)
        ->and($restoredLocal->api_key)->toBeNull();
});

test('the export contains the format header and no active state', function () {
    AiProvider::factory()->active()->create();

    $document = json_decode((new ProviderTransfer)->export()->toJson(), true);

    expect($document['format'])->toBe('tory-ai-providers')
        ->and($document['version'])->toBe(1)
        ->and($document['providers'][0])->toHaveKeys(['uuid', 'name', 'vendor', 'api_key', 'url', 'model'])
        ->and($document['providers'][0])->not->toHaveKey('is_active');
});

test('an existing uuid is skipped and left unchanged, even with another key in the file', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-local-key-0000', 'model' => 'lokal']);

    $result = (new ProviderTransfer)->import(transferFile([
        transferEntry(['uuid' => $provider->id, 'name' => 'Anders', 'model' => 'anders']),
    ]));

    $fresh = $provider->fresh();

    expect($result)->toEqual(new ProviderImportResult(created: 0, skipped: 1, renamed: 0))
        ->and($fresh->name)->toBe($provider->name)
        ->and($fresh->model)->toBe('lokal')
        ->and($fresh->api_key)->toBe('sk-local-key-0000');
});

test('importing the same file twice creates nothing twice', function () {
    $file = transferFile([transferEntry()]);
    $transfer = new ProviderTransfer;

    $transfer->import($file);

    expect($transfer->import($file))->toEqual(new ProviderImportResult(created: 0, skipped: 1, renamed: 0))
        ->and(AiProvider::query()->count())->toBe(1);
});

test('a new uuid with a taken name is renamed, also a second time', function () {
    AiProvider::factory()->create(['name' => 'OpenAI']);

    $result = (new ProviderTransfer)->import(transferFile([
        transferEntry(),
        transferEntry(),
    ]));

    expect($result)->toEqual(new ProviderImportResult(created: 2, skipped: 0, renamed: 2))
        ->and(AiProvider::query()->pluck('name')->sort()->values()->all())
        ->toBe(['OpenAI', 'OpenAI (Import 2)', 'OpenAI (Import)']);
});

test('a renamed entry stays within the name length', function () {
    $name = str_repeat('a', 255);
    AiProvider::factory()->create(['name' => $name]);

    (new ProviderTransfer)->import(transferFile([transferEntry(['name' => $name])]));

    expect(AiProvider::query()->where('name', '!=', $name)->firstOrFail()->name)
        ->toHaveLength(255)
        ->toEndWith(' (Import)');
});

test('an import activates nothing and leaves the active provider active', function () {
    $active = AiProvider::factory()->active()->create();

    (new ProviderTransfer)->import(transferFile([transferEntry(['is_active' => true])]));

    expect(AiProvider::active()?->is($active))->toBeTrue()
        ->and(AiProvider::query()->where('is_active', true)->count())->toBe(1);
});

test('a provider with an unreadable key is left out of the export and counted', function () {
    $broken = AiProvider::factory()->create();
    AiProvider::factory()->create();
    DB::table('ai_providers')->where('id', $broken->id)->update(['api_key' => 'not-a-valid-payload']);

    $result = (new ProviderTransfer)->export();

    expect($result->omitted)->toBe(1)
        ->and($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->uuid)->not->toBe($broken->id);
});

test('an Ollama entry stores no key from the file', function () {
    (new ProviderTransfer)->import(transferFile([transferEntry([
        'vendor' => 'ollama',
        'api_key' => 'sk-should-not-be-stored',
        'url' => 'http://ollama:11434/',
    ])]));

    $provider = AiProvider::query()->firstOrFail();

    expect($provider->api_key)->toBeNull()
        ->and($provider->key_hint)->toBeNull()
        ->and($provider->url)->toBe('http://ollama:11434');
});

test('a cloud entry stores a trimmed key and no address', function () {
    (new ProviderTransfer)->import(transferFile([transferEntry([
        'api_key' => "  sk-file-key-1234\n",
        'url' => 'http://somewhere',
    ])]));

    $provider = AiProvider::query()->firstOrFail();

    expect($provider->api_key)->toBe('sk-file-key-1234')
        ->and($provider->url)->toBeNull();
});

test('an invalid file imports nothing and does not repeat its contents', function (string $file) {
    expect(fn () => (new ProviderTransfer)->import($file))
        ->toThrow(function (InvalidArgumentException $exception) {
            expect($exception->getMessage())->not->toContain('sk-file-key-1234');
        });

    expect(AiProvider::query()->count())->toBe(0);
})->with([
    'broken json' => ['{"providers": [sk-file-key-1234'],
    'not an object' => ['"sk-file-key-1234"'],
    'wrong format' => fn () => json_encode(['format' => 'other', 'version' => 1, 'providers' => [transferEntry()]]),
    'wrong version' => fn () => json_encode(['format' => 'tory-ai-providers', 'version' => 2, 'providers' => [transferEntry()]]),
    'no providers' => fn () => json_encode(['format' => 'tory-ai-providers', 'version' => 1]),
    'missing key' => fn () => transferFile([transferEntry(['name' => 'Gut']), transferEntry(['api_key' => null])]),
    'blank key' => fn () => transferFile([transferEntry(['api_key' => '   '])]),
    'invalid uuid' => fn () => transferFile([transferEntry(['uuid' => 'sk-file-key-1234'])]),
    'duplicate uuid' => function () {
        $uuid = (string) Str::uuid();

        return transferFile([transferEntry(['uuid' => $uuid]), transferEntry(['uuid' => $uuid])]);
    },
    'unknown vendor' => fn () => transferFile([transferEntry(['vendor' => 'sk-file-key-1234'])]),
    'ollama without address' => fn () => transferFile([transferEntry(['vendor' => 'ollama', 'url' => null])]),
    'ollama with a bad address' => fn () => transferFile([transferEntry(['vendor' => 'ollama', 'url' => 'sk-file-key-1234'])]),
    'name too long' => fn () => transferFile([transferEntry(['name' => str_repeat('a', 256)])]),
    'model missing' => fn () => transferFile([transferEntry(['model' => ''])]),
    'whitespace-only model and no embedding model' => fn () => transferFile([transferEntry(['model' => '   '])]),
    'whitespace-only model and embedding model' => fn () => transferFile([transferEntry(['model' => '  ', 'embedding_model' => ' '])]),
    'claude without model' => fn () => transferFile([transferEntry(['vendor' => 'anthropic', 'model' => null, 'embedding_model' => 'sk-file-key-1234'])]),
    'threshold above one' => fn () => transferFile([transferEntry(['search_min_similarity' => 1.5])]),
    'threshold below zero' => fn () => transferFile([transferEntry(['search_min_similarity' => -0.1])]),
    'key is not a string' => fn () => transferFile([transferEntry(['api_key' => ['sk-file-key-1234']])]),
]);

test('the error for an invalid entry names its position', function () {
    expect(fn () => (new ProviderTransfer)->import(transferFile([transferEntry(), transferEntry(['model' => ''])])))
        ->toThrow(InvalidArgumentException::class, 'Eintrag 2');
});

test('export and import keep the embedding fields', function () {
    $provider = AiProvider::factory()->embedding('bge-m3')->create(['search_min_similarity' => 0.42]);
    $transfer = new ProviderTransfer;

    $json = $transfer->export()->toJson();
    DB::table('ai_providers')->delete();
    $transfer->import($json);

    $restored = AiProvider::query()->whereKey($provider->id)->firstOrFail();

    expect($restored->embedding_model)->toBe('bge-m3')
        ->and($restored->search_min_similarity)->toEqual(0.42)
        ->and($restored->model)->toBe($provider->model);
});

test('neither active role is transferred', function () {
    Queue::fake();
    $provider = AiProvider::factory()->activeForSearch()->active()->create();
    $transfer = new ProviderTransfer;

    $json = $transfer->export()->toJson();
    DB::table('ai_providers')->delete();
    $transfer->import($json);

    $restored = AiProvider::query()->whereKey($provider->id)->firstOrFail();

    expect($restored->is_active)->toBeFalse()
        ->and($restored->is_active_for_search)->toBeFalse()
        ->and($restored->embedding_model)->not->toBeNull();
    Queue::assertNotPushed(ReembedItems::class);
});

test('an old file without the new fields still imports', function () {
    (new ProviderTransfer)->import(transferFile([transferEntry()]));

    $provider = AiProvider::query()->firstOrFail();

    expect($provider->embedding_model)->toBeNull()
        ->and($provider->search_min_similarity)->toBeNull();
});

test('an entry with only an embedding model imports', function () {
    (new ProviderTransfer)->import(transferFile([transferEntry(['model' => null, 'embedding_model' => ' bge-m3 '])]));

    $provider = AiProvider::query()->firstOrFail();

    expect($provider->model)->toBeNull()
        ->and($provider->embedding_model)->toBe('bge-m3');
});

test('a whitespace-only model counts as absent when an embedding model exists', function () {
    (new ProviderTransfer)->import(transferFile([transferEntry(['model' => '   ', 'embedding_model' => 'bge-m3'])]));

    expect(AiProvider::query()->firstOrFail()->model)->toBeNull();
});

test('an entry without any model fails the whole import', function () {
    expect(fn () => (new ProviderTransfer)->import(transferFile([
        transferEntry(['name' => 'Gut']),
        transferEntry(['model' => null, 'embedding_model' => null]),
    ])))->toThrow(InvalidArgumentException::class, 'Eintrag 2');

    expect(AiProvider::query()->count())->toBe(0);
});

test('claude entries need a model and drop an embedding model', function () {
    (new ProviderTransfer)->import(transferFile([transferEntry([
        'vendor' => 'anthropic',
        'model' => 'claude-x',
        'embedding_model' => 'bge-m3',
        'search_min_similarity' => 0.6,
    ])]));

    $provider = AiProvider::query()->firstOrFail();

    expect($provider->model)->toBe('claude-x')
        ->and($provider->embedding_model)->toBeNull()
        ->and($provider->search_min_similarity)->toBeNull();
});

test('a provider without a chat model survives a round trip', function () {
    $provider = AiProvider::factory()->embedding('bge-m3')->create(['model' => null]);
    $transfer = new ProviderTransfer;

    $json = $transfer->export()->toJson();
    DB::table('ai_providers')->delete();
    $transfer->import($json);

    $restored = AiProvider::query()->whereKey($provider->id)->firstOrFail();

    expect($restored->model)->toBeNull()
        ->and($restored->embedding_model)->toBe('bge-m3');
});

test('a threshold without an embedding model is stored as given', function () {
    (new ProviderTransfer)->import(transferFile([transferEntry(['search_min_similarity' => 0.3])]));

    expect(AiProvider::query()->firstOrFail()->search_min_similarity)->toEqual(0.3);
});
