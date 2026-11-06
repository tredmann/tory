<?php

use App\Ai\ProviderFailure;
use App\Ai\SearchProviderSwitch;
use App\Jobs\ReembedItems;
use App\Models\AiProvider;
use App\Models\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

test('switching activates the provider, empties all embeddings and queues the job', function () {
    Queue::fake();
    $old = AiProvider::factory()->activeForSearch()->create();
    $new = AiProvider::factory()->embedding()->create();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.1)])->saveQuietly();
    $updatedAt = $item->fresh()->updated_at;
    $this->travel(1)->minutes();

    app(SearchProviderSwitch::class)->switchTo($new, 'text-embedding-3-large');

    expect($old->fresh()->is_active_for_search)->toBeFalse()
        ->and($new->fresh()->is_active_for_search)->toBeTrue()
        ->and($new->fresh()->embedding_model)->toBe('text-embedding-3-large')
        ->and($new->is_active_for_search)->toBeTrue()
        ->and($new->embedding_model)->toBe('text-embedding-3-large')
        ->and($item->fresh()->embedding)->toBeNull()
        ->and($item->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    Queue::assertPushed(ReembedItems::class);
});

test('switching clears a stored failure', function () {
    Queue::fake();
    $provider = AiProvider::factory()->embedding()->create();
    $provider->forceFill(['search_failure' => 'kaputt'])->saveQuietly();

    app(SearchProviderSwitch::class)->switchTo($provider, 'text-embedding-3-small');

    expect($provider->fresh()->search_failure)->toBeNull()
        ->and($provider->search_failure)->toBeNull();
});

test('the probe returns null for a good model', function () {
    Embeddings::fake();
    $provider = AiProvider::factory()->embedding()->create();

    expect(app(SearchProviderSwitch::class)->probe($provider, 'text-embedding-3-small'))->toBeNull();
});

test('the probe names the wrong dimensions', function () {
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);
    $provider = AiProvider::factory()->embedding()->create(['name' => 'OpenAI']);

    expect(app(SearchProviderSwitch::class)->probe($provider, 'x'))
        ->toBe('Das Embedding-Modell von OpenAI liefert 768 statt 1024 Dimensionen.');
});

test('the probe reports an unreadable key instead of throwing', function () {
    $provider = AiProvider::factory()->embedding()->create(['name' => 'OpenAI']);
    DB::table('ai_providers')->where('id', $provider->id)->update(['api_key' => 'kaputt']);

    expect(app(SearchProviderSwitch::class)->probe($provider->fresh(), 'x'))
        ->toBe('Der gespeicherte API-Key von OpenAI ist nicht mehr lesbar.');
});

test('the probe falls back to a generic message for an unclassified failure', function () {
    Embeddings::fake(fn () => throw new RuntimeException('boom'));
    $provider = AiProvider::factory()->embedding()->create();

    expect(app(SearchProviderSwitch::class)->probe($provider, 'x'))
        ->toBe(ProviderFailure::GENERIC_EMBEDDING_MESSAGE);
});

test('switching gives a missing threshold the default and keeps an existing one', function () {
    Queue::fake();
    $without = AiProvider::factory()->embedding()->create();
    $with = AiProvider::factory()->embedding()->create(['search_min_similarity' => 0.7]);
    DB::table('ai_providers')->where('id', $without->id)->update(['search_min_similarity' => null]);
    $without->refresh();

    app(SearchProviderSwitch::class)->switchTo($without, 'm');

    expect($without->fresh()->search_min_similarity)->toBe(AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY)
        ->and($without->search_min_similarity)->toBe(AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY);

    app(SearchProviderSwitch::class)->switchTo($with, 'm');

    expect($with->fresh()->search_min_similarity)->toBe(0.7);
});

test('switching to a deleted provider throws and changes nothing', function () {
    Queue::fake();
    $old = AiProvider::factory()->activeForSearch()->create();
    $gone = AiProvider::factory()->embedding()->create();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.1)])->saveQuietly();
    DB::table('ai_providers')->where('id', $gone->id)->delete();

    expect(fn () => app(SearchProviderSwitch::class)->switchTo($gone, 'm'))->toThrow(ModelNotFoundException::class);

    expect($old->fresh()->is_active_for_search)->toBeTrue()
        ->and($item->fresh()->embedding)->not->toBeNull();
    Queue::assertNotPushed(ReembedItems::class);
});
