<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\ProviderFailure;
use App\Ai\ProviderTester;
use App\Enums\Language;
use App\Models\AiProvider;
use App\Models\HouseholdSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

test('succeeds on a complete structured answer with one image and the test timeout', function () {
    InventoryExtractor::fake([['title' => 'Rotes Quadrat', 'description' => 'Rot.']]);
    $provider = AiProvider::factory()->create(['model' => 'gpt-x']);

    expect(app(ProviderTester::class)->run($provider))->toBeNull();

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 1
        && $prompt->timeout === ProviderTester::TIMEOUT_SECONDS
        && $prompt->model === 'gpt-x');
});

test('reports the reason for a classified failure', function () {
    Http::fake(['*' => Http::response([], 401)]);
    $provider = AiProvider::factory()->create(['name' => 'Claude']);

    expect(app(ProviderTester::class)->run($provider))->toBe('Claude hat den API-Key abgelehnt.');
});

test('reports a general reason for an incomplete answer or unknown failure', function () {
    Exceptions::fake();
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-9999']);
    Http::fake(['*' => Http::response(['error' => 'boom sk-secret-9999'], 500)]);

    $message = app(ProviderTester::class)->run($provider);

    expect($message)->toBeString()->toContain('fehlgeschlagen')->not->toContain('boom')->not->toContain('sk-secret-9999');
    Exceptions::assertReported(fn (ProviderFailure $e) => ! str_contains($e->getMessage(), 'sk-secret-9999'));

    InventoryExtractor::fake([['title' => '', 'description' => '']]);

    expect(app(ProviderTester::class)->run($provider))->toContain('fehlgeschlagen');
});

test('an unreadable key reports the reason instead of throwing', function () {
    $provider = AiProvider::factory()->create(['name' => 'Claude']);
    DB::table('ai_providers')->where('id', $provider->id)->update(['api_key' => 'kaputt']);

    expect(app(ProviderTester::class)->run($provider->fresh()))
        ->toBe('Der gespeicherte API-Key von Claude ist nicht mehr lesbar.');
});

test('only an embedding model runs only the probe', function () {
    InventoryExtractor::fake();
    $provider = AiProvider::factory()->embedding()->create(['model' => null]);

    expect(app(ProviderTester::class)->run($provider))->toBeNull();

    InventoryExtractor::assertNeverPrompted();
    Embeddings::assertGenerated(fn () => true);
});

test('only a model for object recognition does not embed', function () {
    InventoryExtractor::fake([['title' => 'Rotes Quadrat', 'description' => 'Rot.']]);
    $provider = AiProvider::factory()->create(['model' => 'gpt-x', 'embedding_model' => null]);

    expect(app(ProviderTester::class)->run($provider))->toBeNull();

    Embeddings::assertNothingGenerated();
});

test('both configured and the embedding fails names both', function () {
    InventoryExtractor::fake([['title' => 'Rotes Quadrat', 'description' => 'Rot.']]);
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);
    $provider = AiProvider::factory()->embedding()->create(['name' => 'OpenAI', 'model' => 'gpt-x']);

    expect(app(ProviderTester::class)->run($provider))
        ->toBe('Bilderkennung: ok. Embedding: Das Embedding-Modell von OpenAI liefert 768 statt 1024 Dimensionen.');
});

test('both configured and the extraction fails names both', function () {
    Http::fake(['*' => Http::response([], 401)]);
    $provider = AiProvider::factory()->embedding()->create(['name' => 'Claude', 'model' => 'gpt-x']);

    expect(app(ProviderTester::class)->run($provider))
        ->toStartWith('Bilderkennung: ')
        ->toEndWith('Embedding: ok.');
});

test('tests extraction in the household content language', function () {
    HouseholdSettings::current()->changeContentLanguage(Language::English);
    InventoryExtractor::fake([['title' => 'Red square', 'description' => 'Red.']]);

    app(ProviderTester::class)->run(AiProvider::factory()->create(['model' => 'gpt-x']));

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->agent->language === Language::English);
});
