<?php

use App\Enums\AiVendor;
use App\Models\AiProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('vendor labels and key requirement', function () {
    expect(AiVendor::Ollama->getLabel())->toBe('Ollama')
        ->and(AiVendor::Anthropic->getLabel())->toBe('Claude')
        ->and(AiVendor::OpenAi->getLabel())->toBe('OpenAI')
        ->and(AiVendor::Mistral->getLabel())->toBe('Mistral')
        ->and(AiVendor::Ollama->requiresKey())->toBeFalse()
        ->and(AiVendor::Anthropic->requiresKey())->toBeTrue()
        ->and(AiVendor::OpenAi->requiresKey())->toBeTrue()
        ->and(AiVendor::Mistral->requiresKey())->toBeTrue();
});

test('a new provider gets a uuid and keeps its numeric id', function () {
    $provider = AiProvider::factory()->create();

    expect(Str::isUuid($provider->uuid))->toBeTrue()
        ->and($provider->id)->toBeInt()
        ->and($provider->getKeyName())->toBe('id')
        ->and($provider->getIncrementing())->toBeTrue();
});

test('the key is encrypted at rest and hidden from arrays', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);

    expect(DB::table('ai_providers')->value('api_key'))->not->toContain('sk-secret')
        ->and($provider->api_key)->toBe('sk-secret-3fa9')
        ->and($provider->toArray())->not->toHaveKey('api_key')
        ->and($provider->attributesToArray())->not->toHaveKey('api_key');
});

test('saving a key stores its last four characters as the hint', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);
    expect($provider->key_hint)->toBe('3fa9');

    $provider->update(['api_key' => 'sk-new-81c2']);
    expect($provider->fresh()->key_hint)->toBe('81c2');

    $provider->update(['model' => 'other-model']);
    expect($provider->fresh()->key_hint)->toBe('81c2');
});

test('activating leaves exactly one active provider', function () {
    $first = AiProvider::factory()->active()->create();
    $second = AiProvider::factory()->create();

    $second->activate();

    expect($first->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and(AiProvider::active()->is($second))->toBeTrue()
        ->and(AiProvider::where('is_active', true)->count())->toBe(1);
});

test('without an active provider none is returned', function () {
    AiProvider::factory()->create();

    expect(AiProvider::active())->toBeNull();
});

test('the database refuses a second active provider', function () {
    $active = AiProvider::factory()->active()->create();
    $other = AiProvider::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('ai_providers')->where('id', $other->id)->update(['is_active' => true])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('ai_providers')->insert([
        'name' => 'Third', 'vendor' => 'ollama', 'model' => 'm', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect($active->fresh()->is_active)->toBeTrue();
});

test('the active provider cannot be deleted', function () {
    $active = AiProvider::factory()->active()->create();
    $inactive = AiProvider::factory()->create();

    expect($active->delete())->toBeFalse()
        ->and(AiProvider::find($active->id))->not->toBeNull()
        ->and($inactive->delete())->toBeTrue()
        ->and(AiProvider::find($inactive->id))->toBeNull();
});

test('an undecryptable key does not throw', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);
    DB::table('ai_providers')->where('id', $provider->id)->update(['api_key' => 'not-a-valid-payload']);

    $fresh = $provider->fresh();

    expect($fresh->readableKey())->toBeNull()
        ->and($fresh->hasUnreadableKey())->toBeTrue()
        ->and($fresh->key_hint)->toBe('3fa9')
        ->and(fn () => $fresh->attributesToArray())->not->toThrow(Throwable::class);
});

test('a readable key is reported as readable', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);

    expect($provider->readableKey())->toBe('sk-secret-3fa9')
        ->and($provider->hasUnreadableKey())->toBeFalse();
});

test('a provider without a key has neither a readable nor an unreadable key', function () {
    $provider = AiProvider::factory()->ollama()->create();

    expect($provider->readableKey())->toBeNull()
        ->and($provider->hasUnreadableKey())->toBeFalse()
        ->and($provider->key_hint)->toBeNull();
});

test('scrub removes the key from text and leaves text unchanged without a key', function () {
    $keyed = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);
    $keyless = AiProvider::factory()->ollama()->create();

    expect($keyed->scrub('Bad key sk-secret-3fa9 rejected, sk-secret-3fa9'))->toBe('Bad key [Key] rejected, [Key]')
        ->and($keyless->scrub('nothing to hide'))->toBe('nothing to hide');
});

test('a stale instance of a meanwhile activated provider cannot be deleted', function () {
    $provider = AiProvider::factory()->create();
    $stale = AiProvider::find($provider->id);

    AiProvider::find($provider->id)->activate();

    expect($stale->delete())->toBeFalse()
        ->and(AiProvider::whereKey($provider->id)->exists())->toBeTrue();
});
