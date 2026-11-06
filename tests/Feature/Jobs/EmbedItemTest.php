<?php

use App\Ai\ItemEmbedder;
use App\Jobs\EmbedItem;
use App\Models\Item;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

test('the job stores a 1024-dimension embedding', function () {
    Queue::fake();
    $item = Item::factory()->create();

    (new EmbedItem($item))->handle(app(ItemEmbedder::class));

    expect($item->fresh()->embedding)->toHaveCount(1024);
});

test('storing the embedding queues nothing', function () {
    Queue::fake();
    $item = Item::factory()->create();

    (new EmbedItem($item))->handle(app(ItemEmbedder::class));

    Queue::assertPushed(EmbedItem::class, 1);
});

test('the job respects the queue timing invariant', function () {
    $job = new EmbedItem(Item::factory()->make());

    expect($job->tries)->toBe(3)->and($job->timeout)->toBe(60)->and($job->timeout)->toBeLessThan(210);
});

test('creating an item queues its embedding', function () {
    Queue::fake();
    $item = Item::factory()->create();

    Queue::assertPushed(EmbedItem::class, fn (EmbedItem $job) => $job->item->is($item));
});

test('changing title or description clears the embedding and queues a new one', function (string $field) {
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update([$field => 'Etwas anderes']);

    expect($item->fresh()->embedding)->toBeNull();
    Queue::assertPushed(EmbedItem::class, 2);
})->with(['title', 'description']);

test('moving an item keeps its embedding and queues nothing', function () {
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update(['storage_location_id' => StorageLocation::factory()->create()->id]);

    expect($item->fresh()->embedding)->not->toBeNull();
    Queue::assertPushed(EmbedItem::class, 1);
});

test('a failing embedding leaves the item in place without an embedding', function () {
    Queue::fake();
    $item = Item::factory()->create();
    Embeddings::fake(fn () => throw new RuntimeException('down'));

    expect(fn () => (new EmbedItem($item))->handle(app(ItemEmbedder::class)))->toThrow(RuntimeException::class);
    expect($item->fresh()->embedding)->toBeNull();
});
