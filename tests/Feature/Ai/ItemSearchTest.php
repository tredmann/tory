<?php

use App\Ai\ItemSearch;
use App\Models\AiProvider;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function () {
    Queue::fake();
});

/** Creates an item with a fixed embedding (or none). */
function itemWith(array $attributes, ?array $embedding = null): Item
{
    $item = Item::factory()->create($attributes);
    $item->forceFill(['embedding' => $embedding])->saveQuietly();

    return $item;
}

/**
 * @return Builder<Item>
 */
function searchItems(string $term): Builder
{
    $query = Item::query();

    app(ItemSearch::class)->apply($query, $term);

    return $query;
}

/** Makes every query embed to the given vector. */
function queriesEmbedTo(array $vector): void
{
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn () => $vector, $prompt->inputs));
}

test('a word matches as a case-insensitive substring of title or description', function () {
    queriesEmbedTo(axis(9));
    $inTitle = itemWith(['title' => 'Kunststoffgehäuse', 'description' => null]);
    $inDescription = itemWith(['title' => 'Box', 'description' => 'Ein GEHÄUSE aus Metall']);
    $other = itemWith(['title' => 'HDMI-Kabel']);

    expect(searchItems('Gehäuse')->pluck('id')->all())
        ->toEqualCanonicalizing([$inTitle->id, $inDescription->id]);
});

test('every word must match', function () {
    queriesEmbedTo(axis(9));
    $both = itemWith(['title' => 'Raspberry Pi', 'description' => 'mit Gehäuse']);
    itemWith(['title' => 'Raspberry Pi', 'description' => 'ohne alles']);

    expect(searchItems('  pi   gehäuse ')->pluck('id')->all())->toBe([$both->id]);
});

test('a query finds an item by meaning without sharing a word', function () {
    queriesEmbedTo(axis(1));
    $similar = itemWith(['title' => 'Pi-Gehäuse'], axis(1));
    itemWith(['title' => 'Gartenschlauch'], axis(2));

    expect(searchItems('Hülle für Einplatinencomputer')->pluck('id')->all())->toBe([$similar->id]);
});

test('keyword matches come before semantic-only matches', function () {
    queriesEmbedTo(axis(1));
    $semantic = itemWith(['title' => 'Pi-Gehäuse'], axis(1));
    $keyword = itemWith(['title' => 'Hülle', 'description' => null], axis(2));
    $keywordWithoutEmbedding = itemWith(['title' => 'Schutzhülle', 'description' => null]);

    expect(searchItems('Hülle')->pluck('id')->all())
        ->toBe([$keyword->id, $keywordWithoutEmbedding->id, $semantic->id]);
});

test('items below the similarity threshold are left out', function () {
    config(['ai.inventory.search_min_similarity' => 0.9]);
    $half = array_fill(0, 1024, 0.0);
    $half[1] = $half[2] = 1.0; // cosine similarity to axis(1) is about 0.71
    queriesEmbedTo(axis(1));
    itemWith(['title' => 'Irgendwas'], $half);

    expect(searchItems('Suchbegriff')->count())->toBe(0);
});

test('search falls back to keywords and reports when embedding fails', function () {
    Exceptions::fake();
    Embeddings::fake(fn () => throw new RuntimeException('down'));
    $match = itemWith(['title' => 'HDMI-Kabel'], axis(1));
    itemWith(['title' => 'Gartenschlauch'], axis(1));

    expect(searchItems('hdmi')->pluck('id')->all())->toBe([$match->id]);
    Exceptions::assertReported(RuntimeException::class);
});

test('search falls back to keywords when the provider returns another dimension', function () {
    Exceptions::fake();
    queriesEmbedTo(array_fill(0, 768, 0.1));
    $match = itemWith(['title' => 'HDMI-Kabel'], axis(1));

    expect(searchItems('hdmi')->pluck('id')->all())->toBe([$match->id]);
});

test('percent, underscore and backslash are literal', function () {
    queriesEmbedTo(axis(9));
    $percent = itemWith(['title' => '100% Baumwolle']);
    itemWith(['title' => '100 Schrauben']);

    expect(searchItems('100%')->pluck('id')->all())->toBe([$percent->id])
        ->and(searchItems('_')->count())->toBe(0)
        ->and(searchItems('\\')->count())->toBe(0);
});

test('a blank term returns everything and embeds nothing', function () {
    Embeddings::fake();
    itemWith(['title' => 'A']);
    itemWith(['title' => 'B']);

    expect(searchItems('   ')->count())->toBe(2)
        ->and(searchItems("\u{00A0}\u{00A0}")->count())->toBe(2);
    Embeddings::assertNothingGenerated();
});

test('finds an item through a search term', function () {
    queriesEmbedTo(axis(9));
    $item = itemWith(['title' => 'Hefter', 'description' => 'Zum Heften', 'search_terms' => ['Tacker']]);
    itemWith(['title' => 'Lampe', 'search_terms' => ['Licht']]);

    expect(searchItems('tacker')->pluck('id')->all())->toBe([$item->id]);
});

test('every word may match in a different field', function () {
    queriesEmbedTo(axis(9));
    $item = itemWith(['title' => 'Hefter', 'description' => null, 'search_terms' => ['Tacker']]);
    itemWith(['title' => 'Hefter', 'description' => null, 'search_terms' => ['Klammer']]);

    expect(searchItems('hefter tacker')->pluck('id')->all())->toBe([$item->id]);
});

test('finds umlauts in search terms', function (string $query) {
    queriesEmbedTo(axis(9));
    $item = itemWith(['title' => 'Hefter', 'description' => null, 'search_terms' => ['Heftgerät']]);

    expect(searchItems($query)->pluck('id')->all())->toBe([$item->id]);
})->with(['heftgerät', 'HEFTGERÄT']);

test('json syntax does not match search terms', function (string $character) {
    Exceptions::fake();
    Embeddings::fake(fn () => throw new RuntimeException('down'));
    itemWith(['title' => 'Hefter', 'description' => null, 'search_terms' => ['A', 'B']]);

    expect(searchItems($character)->count())->toBe(0);
})->with(['[', ']', ',', '"']);

test('the threshold comes from the active search provider', function () {
    config(['ai.inventory.search_min_similarity' => 0.1]);
    AiProvider::factory()->activeForSearch()->create(['search_min_similarity' => 0.9]);
    $half = array_fill(0, 1024, 0.0);
    $half[1] = $half[2] = 1.0;
    queriesEmbedTo(axis(1));
    itemWith(['title' => 'Irgendwas'], $half);

    expect(searchItems('Suchbegriff')->count())->toBe(0);
});

test('a repeated query is embedded once', function () {
    $calls = 0;
    Embeddings::fake(function () use (&$calls) {
        $calls++;

        return [axis(1)];
    });

    app(ItemSearch::class)->apply(Item::query(), 'Kabel');
    app(ItemSearch::class)->apply(Item::query(), 'Kabel');

    expect($calls)->toBe(1);
});

test('a failed query embedding is not cached', function () {
    Exceptions::fake();
    Embeddings::fake(fn () => throw new RuntimeException('down'));
    searchItems('Kabel')->count();

    queriesEmbedTo(axis(1));
    $semantic = itemWith(['title' => 'Irgendwas'], axis(1));

    expect(searchItems('Kabel')->pluck('id')->all())->toBe([$semantic->id]);
});
