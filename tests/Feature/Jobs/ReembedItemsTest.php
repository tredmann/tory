<?php

use App\Ai\EmbeddingRunner;
use App\Ai\ProviderFailure;
use App\Enums\ProviderFailureReason;
use App\Jobs\EmbedItem;
use App\Jobs\ReembedItems;
use App\Models\AiProvider;
use App\Models\Item;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

beforeEach(function () {
    Queue::fake();
    Exceptions::fake();
});

function runReembed(?string $afterId = null): void
{
    (new ReembedItems($afterId))->handle(app(EmbeddingRunner::class));
}

function storeVector(Item $item): void
{
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();
}

function rejectedKey(): RequestException
{
    return new RequestException(new Response(new PsrResponse(401, [], '{}')));
}

test('it embeds every item without an embedding and leaves others alone', function () {
    $missing = Item::factory()->create();
    $present = Item::factory()->create();
    storeVector($present);

    runReembed();

    expect($missing->fresh()->embedding)->toHaveCount(1024)
        ->and($present->fresh()->embedding)->toBe(array_fill(0, 1024, 0.5));
});

test('it respects the queue timing invariant', function () {
    $job = new ReembedItems;

    expect($job->tries)->toBe(1)->and($job->timeout)->toBe(200)
        ->and(ReembedItems::BUDGET_SECONDS + EmbedItem::CLIENT_TIMEOUT)->toBeLessThan($job->timeout);
});

test('it passes the client timeout to the provider', function () {
    Item::factory()->create();
    $timeouts = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$timeouts) {
        $timeouts[] = $prompt->timeout;

        return [Embeddings::fakeEmbedding(1024)];
    });

    runReembed();

    expect($timeouts)->toBe([EmbedItem::CLIENT_TIMEOUT]);
});

test('it stops after three failures in a row and stores the reason', function () {
    $provider = AiProvider::factory()->activeForSearch()->create(['name' => 'OpenAI']);
    Item::factory()->count(5)->create();
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls) {
        $calls++;

        throw rejectedKey();
    });

    runReembed();

    expect($calls)->toBe(3)
        ->and($provider->fresh()->search_failure)->toContain('OpenAI hat den API-Key abgelehnt.');
});

test('it does not store a key in the failure', function () {
    $provider = AiProvider::factory()->activeForSearch()->create();
    Item::factory()->create();
    Embeddings::fake(fn () => throw new RuntimeException('boom sk-secret-9999'));

    runReembed();

    expect($provider->fresh()->search_failure)
        ->toBe('Das Embedding ist fehlgeschlagen. Bitte Modell und Zugangsdaten prüfen.');
});

test('a run without failures clears the stored failure', function () {
    $provider = AiProvider::factory()->activeForSearch()->create();
    DB::table('ai_providers')->where('id', $provider->getKey())->update(['search_failure' => 'alt']);
    Item::factory()->create();

    runReembed();

    expect($provider->fresh()->search_failure)->toBeNull();
});

test('it stores nothing for a provider that is no longer active for search', function () {
    $provider = AiProvider::factory()->activeForSearch()->create();
    Item::factory()->create();
    Embeddings::fake(function () use ($provider) {
        $provider->forceFill(['is_active_for_search' => false])->saveQuietly();

        throw new RuntimeException('down');
    });

    runReembed();

    expect($provider->fresh()->search_failure)->toBeNull();
});

test('it dispatches itself with a cursor when the budget runs out', function () {
    $first = Item::factory()->create();
    Item::factory()->count(2)->create();
    Embeddings::fake(function () {
        $this->travel(151)->seconds();

        return [Embeddings::fakeEmbedding(1024)];
    });

    runReembed();

    expect(Item::whereNotNull('embedding')->count())->toBe(1);
    Queue::assertPushed(ReembedItems::class, fn (ReembedItems $job) => $job->afterId === $first->id);
});

test('it does not dispatch itself when the run completes', function () {
    Item::factory()->count(2)->create();

    runReembed();

    Queue::assertNotPushed(ReembedItems::class);
});

test('an item that always fails does not loop', function () {
    $first = Item::factory()->create(['title' => 'Kaputt']);
    $second = Item::factory()->create(['title' => 'Heil']);
    $broken = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$broken) {
        if ($prompt->contains('Kaputt')) {
            $broken++;
            $this->travel(151)->seconds();

            throw new RuntimeException('down');
        }

        return [Embeddings::fakeEmbedding(1024)];
    });

    runReembed();

    Queue::assertPushed(ReembedItems::class, fn (ReembedItems $job) => $job->afterId === $first->id);

    runReembed($first->id);

    expect($broken)->toBe(1)->and($second->fresh()->embedding)->toHaveCount(1024);
});

test('it continues with the newest target after a switch', function () {
    $old = AiProvider::factory()->activeForSearch()->create(['name' => 'Alt']);
    Item::factory()->count(2)->create();
    $models = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$models, $old) {
        $models[] = $prompt->model;

        if (count($models) === 1) {
            $old->forceFill(['is_active_for_search' => false])->saveQuietly();
            AiProvider::factory()->activeForSearch()->create(['name' => 'Neu', 'embedding_model' => 'neues-modell']);
        }

        return [Embeddings::fakeEmbedding(1024)];
    });

    runReembed();

    expect($models)->toBe(['text-embedding-3-small', 'neues-modell']);
});

test('failed() stores only the generic text for a raw exception', function () {
    $provider = AiProvider::factory()->activeForSearch()->create();

    (new ReembedItems)->failed(new RuntimeException('boom sk-secret-9999'));

    expect($provider->fresh()->search_failure)->toBe(ProviderFailure::GENERIC_EMBEDDING_MESSAGE);
});

test('failed() stores the message of a provider failure with a reason', function () {
    $provider = AiProvider::factory()->activeForSearch()->create(['name' => 'OpenAI']);

    (new ReembedItems)->failed(new ProviderFailure('x sk-secret-9999', ProviderFailureReason::KeyRejected, 'OpenAI'));

    expect($provider->fresh()->search_failure)->toBe('OpenAI hat den API-Key abgelehnt.');
});

test('failed() writes nothing and does not throw without a provider active for search', function () {
    $provider = AiProvider::factory()->embedding()->create();

    (new ReembedItems)->failed(null);

    expect($provider->fresh()->search_failure)->toBeNull();
});
