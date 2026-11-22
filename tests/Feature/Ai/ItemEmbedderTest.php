<?php

use App\Ai\ItemEmbedder;
use App\Ai\NoSearchProvider;
use App\Ai\ProviderFailure;
use App\Enums\AiVendor;
use App\Models\AiProvider;
use App\Models\Item;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Pest\Expectation;

test('it embeds text with the configured provider, model and dimension', function (): void {
    AiProvider::factory()->activeForSearch()->create(['embedding_model' => 'bge-m3']);
    Embeddings::fake();

    $vector = app(ItemEmbedder::class)->embed('Raspberry Pi Gehäuse');

    expect($vector)->toHaveCount(1024);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->contains('Raspberry Pi Gehäuse')
        && $prompt->dimensions === 1024
        && $prompt->provider->driver() === 'openai'
        && $prompt->model === 'bge-m3');
});

test('it applies the given timeout', function (): void {
    activeSearchProvider();
    Embeddings::fake();

    app(ItemEmbedder::class)->embed('x', 12);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->timeout === 12);
});

test('it rejects a vector of another length', function (): void {
    activeSearchProvider();
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);

    expect(fn () => app(ItemEmbedder::class)->embed('x'))->toThrow(ProviderFailure::class);
});

test('the text is title, blank line, description', function (): void {
    $item = Item::factory()->make(['title' => 'Pi-Gehäuse', 'description' => 'Schwarz, Kunststoff']);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe("Pi-Gehäuse\n\nSchwarz, Kunststoff");
});

test('an item without a description is embedded from its title alone', function (): void {
    $item = Item::factory()->make(['title' => 'Pi-Gehäuse', 'description' => null]);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe('Pi-Gehäuse');
});

test('text for includes search terms', function (): void {
    $item = new Item(['title' => 'T', 'description' => 'D', 'search_terms' => ['A', 'B']]);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe("T\n\nD\n\nSuchbegriffe: A, B");
});

test('text for without search terms equals the previous text', function (): void {
    $embedder = app(ItemEmbedder::class);

    expect($embedder->textFor(new Item(['title' => 'T', 'description' => 'D', 'search_terms' => []])))->toBe("T\n\nD")
        ->and($embedder->textFor(new Item(['title' => 'T', 'description' => '', 'search_terms' => []])))->toBe('T');
});

test('text for works with search terms and an empty description', function (): void {
    $item = new Item(['title' => 'T', 'description' => null, 'search_terms' => ['A']]);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe("T\n\nSuchbegriffe: A");
});

test('the active search provider is used instead of the env configuration', function (): void {
    AiProvider::factory()->activeForSearch()->create(['vendor' => AiVendor::OpenAi, 'embedding_model' => 'text-embedding-3-small']);
    Embeddings::fake();

    app(ItemEmbedder::class)->embed('x');

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->model === 'text-embedding-3-small'
        && $prompt->provider->driver() === 'openai');
});

test('a failure on a provider leaves scrubbed', function (): void {
    AiProvider::factory()->activeForSearch()->create(['api_key' => 'sk-secret-9999']);
    Embeddings::fake(fn (): never => throw new RuntimeException('boom sk-secret-9999'));

    expect(fn () => app(ItemEmbedder::class)->embed('x'))
        ->toThrow(fn (ProviderFailure $e) => expect($e->getMessage())->not->toContain('sk-secret-9999'));
});

test('a vector of another length names both lengths', function (): void {
    AiProvider::factory()->activeForSearch()->create(['name' => 'OpenAI']);
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);

    expect(fn () => app(ItemEmbedder::class)->embed('x'))
        ->toThrow(fn (ProviderFailure $e): Expectation => expect($e->userMessage())
            ->toBe('Das Embedding-Modell von OpenAI liefert 768 statt 1024 Dimensionen.'));
});

test('a vector computed before a switch is not stored', function (): void {
    Queue::fake();
    $item = Item::factory()->create();
    $next = AiProvider::factory()->embedding()->create();
    Embeddings::fake(function () use ($next): array {
        $next->forceFill(['is_active_for_search' => true])->saveQuietly();

        return [array_fill(0, 1024, 0.1)];
    });

    expect(app(ItemEmbedder::class)->embedAndStore($item))->toBeFalse()
        ->and($item->fresh()->embedding)->toBeNull();
});

test('without a search provider an item is not embedded', function (): void {
    Queue::fake();
    Embeddings::fake()->preventStrayEmbeddings();
    $item = Item::factory()->create();

    expect(app(ItemEmbedder::class)->embedAndStore($item))->toBeFalse()
        ->and($item->fresh()->embedding)->toBeNull();
});

test('embedding text without a search provider throws NoSearchProvider', function (): void {
    expect(fn () => app(ItemEmbedder::class)->embed('x'))->toThrow(NoSearchProvider::class);
});
