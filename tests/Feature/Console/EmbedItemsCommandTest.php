<?php

use App\Ai\ProviderFailure;
use App\Console\Commands\EmbedItems;
use App\Models\AiProvider;
use App\Models\Item;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function (): void {
    Queue::fake();
});

test('items:embed fills only missing embeddings', function (): void {
    activeSearchProvider();
    $missing = Item::factory()->create();
    $present = Item::factory()->create();
    $present->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $this->artisan('items:embed')->assertSuccessful();

    expect($missing->fresh()->embedding)->toHaveCount(1024)
        ->and($present->fresh()->embedding)->toBe(array_fill(0, 1024, 0.5));
});

test('items:embed --all re-embeds everything', function (): void {
    activeSearchProvider();
    $present = Item::factory()->create();
    $present->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $this->artisan('items:embed', ['--all' => true])->assertSuccessful();

    expect($present->fresh()->embedding)->toHaveCount(1024)
        ->and($present->fresh()->embedding)->not->toBe(array_fill(0, 1024, 0.5));
});

test('items:embed carries on after one item fails', function (): void {
    activeSearchProvider();
    Exceptions::fake();
    Item::factory()->count(2)->create();
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): array {
        return ++$calls === 1 ? throw new RuntimeException('down') : [Embeddings::fakeEmbedding(1024)];
    });

    $this->artisan('items:embed')->assertSuccessful();

    expect(Item::query()->whereNotNull('embedding')->count())->toBe(1);
    Exceptions::assertReported(ProviderFailure::class);
});

test('items:embed stops after three failures in a row', function (): void {
    activeSearchProvider();
    Exceptions::fake();
    Item::factory()->count(5)->create();
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls): void {
        $calls++;

        throw new RuntimeException('down');
    });

    $this->artisan('items:embed')
        ->expectsOutputToContain('vorzeitig beendet')
        ->assertSuccessful();

    expect($calls)->toBe(3);
    Exceptions::assertReportedCount(3);
});

test('items:embed is scheduled hourly', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('0 * * * *  php artisan items:embed');
});

test('items:embed covers a backlog larger than one page', function (): void {
    activeSearchProvider();
    EmbedItems::$pageSize = 2;
    Item::factory()->count(5)->create();

    try {
        $this->artisan('items:embed')->assertSuccessful();
    } finally {
        EmbedItems::$pageSize = 500;
    }

    expect(Item::query()->whereNull('embedding')->count())->toBe(0);
});

test('items:embed with an active provider stores a failure too', function (): void {
    Exceptions::fake();
    $provider = AiProvider::factory()->activeForSearch()->create(['name' => 'OpenAI']);
    Item::factory()->create();
    Embeddings::fake(fn (): never => throw new RuntimeException('boom'));

    $this->artisan('items:embed')->assertSuccessful();

    expect($provider->fresh()->searchFailureMessage())
        ->toBe('Das Embedding ist fehlgeschlagen. Bitte Modell und Zugangsdaten prüfen.');
});

test('items:embed without a search provider says so and succeeds', function (): void {
    AiProvider::query()->update(['is_active_for_search' => false]);
    Item::factory()->create();
    Embeddings::fake()->preventStrayEmbeddings();

    $this->artisan('items:embed')
        ->expectsOutputToContain('Keine KI für die Suche eingerichtet')
        ->assertSuccessful();
});
