<?php

use App\Console\Commands\EmbedItems;
use App\Models\Item;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function () {
    Queue::fake();
});

test('items:embed fills only missing embeddings', function () {
    $missing = Item::factory()->create();
    $present = Item::factory()->create();
    $present->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $this->artisan('items:embed')->assertSuccessful();

    expect($missing->fresh()->embedding)->toHaveCount(1024)
        ->and($present->fresh()->embedding)->toBe(array_fill(0, 1024, 0.5));
});

test('items:embed --all re-embeds everything', function () {
    $present = Item::factory()->create();
    $present->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $this->artisan('items:embed', ['--all' => true])->assertSuccessful();

    expect($present->fresh()->embedding)->toHaveCount(1024)
        ->and($present->fresh()->embedding)->not->toBe(array_fill(0, 1024, 0.5));
});

test('items:embed carries on after one item fails', function () {
    Exceptions::fake();
    Item::factory()->count(2)->create();
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls) {
        return ++$calls === 1 ? throw new RuntimeException('down') : [Embeddings::fakeEmbedding(1024)];
    });

    $this->artisan('items:embed')->assertSuccessful();

    expect(Item::whereNotNull('embedding')->count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

test('items:embed is scheduled hourly', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('0 * * * *  php artisan items:embed');
});

test('items:embed covers a backlog larger than one page', function () {
    EmbedItems::$pageSize = 2;
    Item::factory()->count(5)->create();

    try {
        $this->artisan('items:embed')->assertSuccessful();
    } finally {
        EmbedItems::$pageSize = 500;
    }

    expect(Item::whereNull('embedding')->count())->toBe(0);
});
