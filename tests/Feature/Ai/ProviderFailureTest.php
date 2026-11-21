<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\ProviderFailure;
use App\Ai\WrongEmbeddingDimensions;
use App\Enums\AiVendor;
use App\Enums\ProviderFailureReason;
use App\Models\AiProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function attributesFor(AiVendor $vendor): array
{
    return $vendor === AiVendor::Ollama
        ? ['vendor' => $vendor, 'api_key' => null, 'url' => 'http://ollama.test:11434', 'model' => 'm']
        : ['vendor' => $vendor, 'model' => 'm'];
}

function failureFrom(AiVendor $vendor): Throwable
{
    $provider = AiProvider::factory()->create(attributesFor($vendor));

    try {
        InventoryExtractor::make(aiProvider: $provider)->extract([]);
    } catch (Throwable $e) {
        return $e;
    }

    throw new LogicException('Expected the extraction to fail.');
}

dataset('failures', [
    '401' => [401, [], ProviderFailureReason::KeyRejected],
    '403' => [403, [], ProviderFailureReason::KeyRejected],
    '404' => [404, [], ProviderFailureReason::UnknownModel],
    'invalid model' => [400, ['error' => ['code' => 'invalid_model']], ProviderFailureReason::UnknownModel],
    'no vision' => [400, ['error' => ['message' => 'This model does not support image input']], ProviderFailureReason::ImagesUnsupported],
    'only certain models' => [400, ['error' => ['message' => 'image_url is only supported by certain models']], ProviderFailureReason::ImagesUnsupported],
    'unsupported image' => [400, ['error' => ['message' => 'You uploaded an unsupported image']], null],
    'vision model echoed' => [400, ['model' => 'llama3.2-vision', 'error' => 'bad request'], null],
    'provision' => [400, ['error' => ['message' => 'provision failed']], null],
    'too large' => [400, ['error' => ['message' => 'image exceeds 5 MB']], null],
    'server error' => [500, [], null],
    'credits' => [402, [], null],
    'overloaded' => [503, [], null],
]);

test('classifies vendor failures the same way for every vendor', function (int $status, array $body, ?ProviderFailureReason $expected) {
    foreach (AiVendor::cases() as $vendor) {
        Http::fake(['*' => Http::response($body, $status)]);

        expect(ProviderFailureReason::fromThrowable(failureFrom($vendor)))->toBe($expected, $vendor->value);
    }
})->with('failures');

test('a connection failure is unreachable', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('Connection refused')]);

    expect(ProviderFailureReason::fromThrowable(failureFrom(AiVendor::Ollama)))->toBe(ProviderFailureReason::Unreachable);
});

test('DecryptException is an unreadable key', function () {
    expect(ProviderFailureReason::fromThrowable(new DecryptException('The payload is invalid.')))
        ->toBe(ProviderFailureReason::KeyUnreadable);
});

test('other exceptions are unclassified', function () {
    expect(ProviderFailureReason::fromThrowable(new RuntimeException('boom')))->toBeNull();
});

test('every reason names the provider in a fixed sentence', function (ProviderFailureReason $reason, string $sentence) {
    expect($reason->message('Claude'))->toBe($sentence);
})->with([
    [ProviderFailureReason::KeyRejected, 'Claude hat den API-Key abgelehnt.'],
    [ProviderFailureReason::KeyUnreadable, 'Der gespeicherte API-Key von Claude ist nicht mehr lesbar.'],
    [ProviderFailureReason::UnknownModel, 'Claude kennt das eingestellte Modell nicht.'],
    [ProviderFailureReason::ImagesUnsupported, 'Das bei Claude eingestellte Modell kann keine Fotos verarbeiten.'],
    [ProviderFailureReason::Unreachable, 'Claude ist nicht erreichbar.'],
]);

test('ProviderFailure scrubs the key and drops the previous exception', function () {
    $provider = AiProvider::factory()->create(['name' => 'Claude schnell', 'api_key' => 'sk-secret-3fa9']);

    $failure = ProviderFailure::from(new RuntimeException('bad key sk-secret-3fa9'), $provider);

    expect($failure->getMessage())->toBe('RuntimeException: bad key [Key]')
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->providerName)->toBe('Claude schnell')
        ->and($failure->reason)->toBeNull();
});

test('user message is the dimensions text, else the reason message', function () {
    $provider = AiProvider::factory()->create(['name' => 'Claude']);

    $dimensions = ProviderFailure::from(new WrongEmbeddingDimensions(768), $provider);
    $plain = new ProviderFailure('x', ProviderFailureReason::KeyRejected, 'Claude');

    expect($dimensions->userMessage())->toBe('Das Embedding-Modell von Claude liefert 768 statt 1024 Dimensionen.')
        ->and($plain->userMessage())->toBe('Claude hat den API-Key abgelehnt.')
        ->and(ProviderFailure::from(new RuntimeException('x'), $provider)->userMessage())->toBeNull();
});
