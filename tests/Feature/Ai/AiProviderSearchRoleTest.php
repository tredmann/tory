<?php

use App\Enums\AiVendor;
use App\Models\AiProvider;
use Illuminate\Database\QueryException;

test('at most one provider is active for search', function () {
    AiProvider::factory()->activeForSearch()->create();

    expect(fn () => AiProvider::factory()->activeForSearch()->create())->toThrow(QueryException::class);
});

test('a provider active for search cannot be deleted', function () {
    $provider = AiProvider::factory()->activeForSearch()->create();

    $provider->delete();

    expect(AiProvider::query()->whereKey($provider->id)->exists())->toBeTrue();
});

test('the search role is independent of the extraction role', function () {
    $search = AiProvider::factory()->activeForSearch()->create();
    $extraction = AiProvider::factory()->active()->create();

    expect(AiProvider::activeForSearch()->is($search))->toBeTrue()
        ->and(AiProvider::active()->is($extraction))->toBeTrue();
});

test('claude offers no embeddings', function () {
    expect(AiVendor::Anthropic->supportsEmbeddings())->toBeFalse()
        ->and(AiVendor::OpenAi->supportsEmbeddings())->toBeTrue();
});
