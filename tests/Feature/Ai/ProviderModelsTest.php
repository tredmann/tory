<?php

use App\Ai\ProviderModels;
use App\Enums\AiVendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

test('lists Ollama models from its tags endpoint', function () {
    Http::fake(['http://ollama:11434/api/tags' => Http::response(['models' => [['name' => 'b:1'], ['name' => 'a:1']]])]);

    expect(ProviderModels::for(AiVendor::Ollama, null, 'http://ollama:11434'))->toBe(['a:1', 'b:1']);
});

test('sends the vendor credentials', function (AiVendor $vendor, string $endpoint, string $header, string $value) {
    Http::fake([$endpoint.'*' => Http::response(['data' => [['id' => 'm']]])]);

    expect(ProviderModels::for($vendor, 'secret-key', null))->toBe(['m']);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), $endpoint)
        && $request->header($header) === [$value]);
})->with([
    'anthropic' => [AiVendor::Anthropic, 'https://api.anthropic.com/v1/models', 'x-api-key', 'secret-key'],
    'openai' => [AiVendor::OpenAi, 'https://api.openai.com/v1/models', 'Authorization', 'Bearer secret-key'],
    'mistral' => [AiVendor::Mistral, 'https://api.mistral.ai/v1/models', 'Authorization', 'Bearer secret-key'],
]);

test('Anthropic requests carry the API version and a high limit', function () {
    Http::fake(['https://api.anthropic.com/*' => Http::response(['data' => []])]);

    ProviderModels::for(AiVendor::Anthropic, 'k', null);

    Http::assertSent(fn (Request $request): bool => $request->header('anthropic-version') === ['2023-06-01']
        && str_contains($request->url(), 'limit=1000'));
});

test('returns an empty list without calling out when the credential is missing', function (AiVendor $vendor, ?string $key, ?string $url) {
    Http::fake();

    expect(ProviderModels::for($vendor, $key, $url))->toBe([]);

    Http::assertNothingSent();
})->with([
    'ollama without url' => [AiVendor::Ollama, null, ''],
    'anthropic without key' => [AiVendor::Anthropic, '', null],
    'openai without key' => [AiVendor::OpenAi, null, null],
]);

test('returns null on an error status or connection failure', function () {
    Http::fake(['http://down:11434/*' => Http::response('boom', 500)]);
    expect(ProviderModels::for(AiVendor::Ollama, null, 'http://down:11434'))->toBeNull();

    Http::fake(['http://gone:11434/*' => fn () => throw new ConnectionException('no route')]);
    expect(ProviderModels::for(AiVendor::Ollama, null, 'http://gone:11434'))->toBeNull();
});

test('returns null on 200 with an unexpected JSON shape', function (mixed $body) {
    Http::fake(['http://odd:11434/*' => Http::response($body)]);

    expect(ProviderModels::for(AiVendor::Ollama, null, 'http://odd:11434'))->toBeNull();
})->with([
    'wrong keys' => [['foo' => 'bar']],
    'not json' => ['<html>nope</html>'],
]);

test('caches the outcome, failures included, without the plain key in the cache key', function () {
    Http::fake(['https://api.openai.com/*' => Http::response('boom', 500)]);

    ProviderModels::for(AiVendor::OpenAi, 'sk-plain-key', null);
    ProviderModels::for(AiVendor::OpenAi, 'sk-plain-key', null);

    Http::assertSentCount(1);

    $store = Cache::getStore();
    expect(json_encode(new ReflectionProperty($store, 'storage')->getValue($store)))->not->toContain('sk-plain-key');
});
