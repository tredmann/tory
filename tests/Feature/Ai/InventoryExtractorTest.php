<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\ExtractedTexts;
use App\Enums\AiVendor;
use App\Models\AiProvider;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Files\Image;

test('provider and model come from the inventory config', function () {
    config(['ai.inventory.provider' => 'ollama', 'ai.inventory.model' => 'gemma4:e4b-mlx']);
    $agent = new InventoryExtractor;

    expect($agent->provider())->toBe('ollama')
        ->and($agent->model())->toBe('gemma4:e4b-mlx');
});

test('the model falls back to the provider default when unset', function () {
    config(['ai.inventory.model' => null]);

    expect((new InventoryExtractor)->model())->toBeNull();
});

test('uses a 180 second timeout', function () {
    expect((new InventoryExtractor)->timeout())->toBe(180);
});

test('returns a structured title and description for the attached images', function () {
    InventoryExtractor::fake([
        ['title' => 'Raspberry Pi 4 Model B', 'description' => 'Grüne Platine mit vier USB-Anschlüssen.'],
    ]);

    $response = InventoryExtractor::make()->prompt('Beschreibe das Objekt.', [
        Image::fromBase64(base64_encode('a'), 'image/png'),
        Image::fromBase64(base64_encode('b'), 'image/png'),
    ]);

    expect($response->toArray())->toBe([
        'title' => 'Raspberry Pi 4 Model B',
        'description' => 'Grüne Platine mit vier USB-Anschlüssen.',
    ]);

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2);
});

test('instructions ask for German texts with search terms and forbid inventing details', function () {
    $instructions = (string) (new InventoryExtractor)->instructions();

    expect($instructions)
        ->toContain('Deutsch')
        ->toContain('Erfinde')
        ->toContain('search_terms')
        ->not->toContain('sichtbaren Zustand');
});

test('the schema requires search terms', function () {
    $schema = (new InventoryExtractor)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['title', 'description', 'search_terms']);
});

test('an empty model env value falls back to the provider default', function () {
    config(['ai.inventory.model' => '']);

    expect((new InventoryExtractor)->model())->toBeNull();
});

test('an empty provider env value falls back to anthropic', function () {
    config(['ai.inventory.provider' => '']);

    expect((new InventoryExtractor)->provider())->toBe('anthropic');
});

test('builds its provider from the given AI provider', function () {
    $aiProvider = AiProvider::factory()->create([
        'vendor' => AiVendor::Mistral, 'api_key' => 'mk-1', 'model' => 'pixtral-x',
    ]);
    $agent = new InventoryExtractor($aiProvider);

    expect($agent->provider()->driver())->toBe('mistral')
        ->and($agent->provider()->providerCredentials()['key'])->toBe('mk-1')
        ->and($agent->model())->toBe('pixtral-x');
});

test('an Ollama provider uses its stored address and needs no key', function () {
    $aiProvider = AiProvider::factory()->create([
        'vendor' => AiVendor::Ollama, 'api_key' => null, 'url' => 'http://ollama.test:11434', 'model' => 'gemma',
    ]);
    $provider = (new InventoryExtractor($aiProvider))->provider();

    expect($provider->driver())->toBe('ollama')
        ->and($provider->providerCredentials()['key'])->toBe('')
        ->and($provider->additionalConfiguration()['url'])->toBe('http://ollama.test:11434');
});

test('extract rejects an incomplete answer', function () {
    InventoryExtractor::fake([['title' => 'Nur Titel', 'description' => ' ']]);

    InventoryExtractor::make()->extract([]);
})->throws(RuntimeException::class, 'Das Modell hat keine vollständige Antwort geliefert.');

test('extract returns a DTO with cleaned search terms', function () {
    InventoryExtractor::fake([[
        'title' => 'Hefter', 'description' => 'Roter Hefter.',
        'search_terms' => [' Hefter ', 'hefter', 'Tacker', '', 42],
    ]]);

    $texts = InventoryExtractor::make()->extract([]);

    expect($texts)->toBeInstanceOf(ExtractedTexts::class)
        ->and($texts->title)->toBe('Hefter')
        ->and($texts->description)->toBe('Roter Hefter.')
        ->and($texts->searchTerms)->toBe(['Hefter', 'Tacker']);
});

test('extract tolerates missing or unusable search terms', function (array $extra) {
    InventoryExtractor::fake([['title' => 'Hefter', 'description' => 'Roter Hefter.', ...$extra]]);

    expect(InventoryExtractor::make()->extract([])->searchTerms)->toBe([]);
})->with([
    'missing key' => [[]],
    'string' => [['search_terms' => 'Hefter, Tacker']],
    'null' => [['search_terms' => null]],
]);
