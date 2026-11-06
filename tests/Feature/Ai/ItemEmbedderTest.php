<?php

use App\Ai\ItemEmbedder;
use App\Models\Item;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('it embeds text with the configured provider, model and dimension', function () {
    config(['ai.inventory.embedding_provider' => 'ollama', 'ai.inventory.embedding_model' => 'bge-m3']);
    Embeddings::fake();

    $vector = app(ItemEmbedder::class)->embed('Raspberry Pi Gehäuse');

    expect($vector)->toHaveCount(1024);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->contains('Raspberry Pi Gehäuse')
        && $prompt->dimensions === 1024
        && $prompt->provider->name() === 'ollama'
        && $prompt->model === 'bge-m3');
});

test('it applies the given timeout', function () {
    Embeddings::fake();

    app(ItemEmbedder::class)->embed('x', 12);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->timeout === 12);
});

test('it rejects a vector of another length', function () {
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);

    expect(fn () => app(ItemEmbedder::class)->embed('x'))->toThrow(RuntimeException::class);
});

test('the text is title, blank line, description', function () {
    $item = Item::factory()->make(['title' => 'Pi-Gehäuse', 'description' => 'Schwarz, Kunststoff']);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe("Pi-Gehäuse\n\nSchwarz, Kunststoff");
});

test('an item without a description is embedded from its title alone', function () {
    $item = Item::factory()->make(['title' => 'Pi-Gehäuse', 'description' => null]);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe('Pi-Gehäuse');
});

test('text for includes search terms', function () {
    $item = new Item(['title' => 'T', 'description' => 'D', 'search_terms' => ['A', 'B']]);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe("T\n\nD\n\nSuchbegriffe: A, B");
});

test('text for without search terms equals the previous text', function () {
    $embedder = app(ItemEmbedder::class);

    expect($embedder->textFor(new Item(['title' => 'T', 'description' => 'D', 'search_terms' => []])))->toBe("T\n\nD")
        ->and($embedder->textFor(new Item(['title' => 'T', 'description' => '', 'search_terms' => []])))->toBe('T');
});

test('text for works with search terms and an empty description', function () {
    $item = new Item(['title' => 'T', 'description' => null, 'search_terms' => ['A']]);

    expect(app(ItemEmbedder::class)->textFor($item))->toBe("T\n\nSuchbegriffe: A");
});
