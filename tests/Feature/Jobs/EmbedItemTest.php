<?php

use App\Ai\ItemEmbedder;
use App\Jobs\EmbedItem;
use App\Models\AiProvider;
use App\Models\Item;
use App\Models\Spot;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('the job stores a 1024-dimension embedding', function (): void {
    activeSearchProvider();
    Queue::fake();
    $item = Item::factory()->create();

    new EmbedItem($item)->handle(app(ItemEmbedder::class));

    expect($item->fresh()->embedding)->toHaveCount(1024);
});

test('storing the embedding queues nothing', function (): void {
    Queue::fake();
    $item = Item::factory()->create();

    new EmbedItem($item)->handle(app(ItemEmbedder::class));

    Queue::assertPushed(EmbedItem::class, 1);
});

test('the job respects the queue timing invariant', function (): void {
    $job = new EmbedItem(Item::factory()->make());

    expect($job->tries)->toBe(3)->and($job->timeout)->toBe(60)->and($job->timeout)->toBeLessThan(210)
        ->and(EmbedItem::CLIENT_TIMEOUT)->toBe(45)->and(EmbedItem::CLIENT_TIMEOUT)->toBeLessThan($job->timeout);
});

test('creating an item queues its embedding', function (): void {
    Queue::fake();
    $item = Item::factory()->create();

    Queue::assertPushed(EmbedItem::class, fn (EmbedItem $job) => $job->item->is($item));
});

test('changing title or description clears the embedding and queues a new one', function (string $field): void {
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update([$field => 'Etwas anderes']);

    expect($item->fresh()->embedding)->toBeNull();
    Queue::assertPushed(EmbedItem::class, 2);
})->with(['title', 'description']);

test('changing search terms clears the embedding and queues a new one', function (): void {
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update(['search_terms' => ['Tacker']]);

    expect($item->fresh()->embedding)->toBeNull();
    Queue::assertPushed(EmbedItem::class, 2);
});

test('saving unchanged search terms queues nothing', function (): void {
    Queue::fake();
    $item = Item::factory()->create(['search_terms' => ['Tacker']]);
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update(['search_terms' => ['Tacker']]);

    expect($item->fresh()->embedding)->not->toBeNull();
    Queue::assertPushed(EmbedItem::class, 1);
});

test('moving an item keeps its embedding and queues nothing', function (): void {
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update(['spot_id' => Spot::factory()->create()->id]);

    expect($item->fresh()->embedding)->not->toBeNull();
    Queue::assertPushed(EmbedItem::class, 1);
});

test('a failing embedding leaves the item in place without an embedding', function (): void {
    activeSearchProvider();
    Queue::fake();
    $item = Item::factory()->create();
    Embeddings::fake(fn (): never => throw new RuntimeException('down'));

    expect(fn () => new EmbedItem($item)->handle(app(ItemEmbedder::class)))->toThrow(RuntimeException::class);
    expect($item->fresh()->embedding)->toBeNull();
});

test('the job asks the provider with a client timeout shorter than its own', function (): void {
    activeSearchProvider();
    Queue::fake();
    $item = Item::factory()->create();
    $timeouts = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$timeouts): array {
        $timeouts[] = $prompt->timeout;

        return [Embeddings::fakeEmbedding(1024)];
    });

    new EmbedItem($item)->handle(app(ItemEmbedder::class));

    expect($timeouts)->toBe([45]);
});

test('an embedding of text that was edited meanwhile is not stored', function (): void {
    activeSearchProvider();
    Queue::fake();
    $item = Item::factory()->create(['title' => 'Alt']);
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($item): array {
        Item::query()->find($item->id)->update(['title' => 'Neu']);

        return [Embeddings::fakeEmbedding(1024)];
    });

    new EmbedItem($item)->handle(app(ItemEmbedder::class));

    expect($item->fresh())->title->toBe('Neu')->embedding->toBeNull();
});

test('storing an embedding leaves updated_at alone', function (): void {
    activeSearchProvider();
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $before = $item->fresh()->updated_at;

    new EmbedItem($item)->handle(app(ItemEmbedder::class));

    expect($item->fresh()->embedding)->toHaveCount(1024)
        ->and($item->fresh()->updated_at->equalTo($before))->toBeTrue();
});

test('the job does nothing without a search provider', function (): void {
    Queue::fake();
    Embeddings::fake()->preventStrayEmbeddings();
    $item = Item::factory()->create();
    AiProvider::query()->update(['is_active_for_search' => false]);

    new EmbedItem($item)->handle(app(ItemEmbedder::class));

    expect($item->fresh()->embedding)->toBeNull();
});
