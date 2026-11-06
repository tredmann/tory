<?php

use App\Enums\AiVendor;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Resources\Imports\ImportResource;
use App\Models\AiProvider;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('creates a cloud provider inactive, with an encrypted key', function () {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Mein Claude',
            'vendor' => AiVendor::Anthropic->value,
            'api_key' => 'sk-abc-1234',
            'model' => 'claude-sonnet-4-5',
        ])
        ->assertHasNoFormErrors();

    $provider = AiProvider::sole();
    expect($provider->is_active)->toBeFalse()
        ->and($provider->key_hint)->toBe('1234')
        ->and($provider->api_key)->toBe('sk-abc-1234');
    expect(DB::table('ai_providers')->value('api_key'))->not->toContain('sk-abc');
});

test('an Ollama provider needs an address and no key', function () {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Lokal',
            'vendor' => AiVendor::Ollama->value,
            'url' => '',
            'model' => 'gemma4',
        ])
        ->assertHasFormErrors(['url' => 'required']);

    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Lokal',
            'vendor' => AiVendor::Ollama->value,
            'url' => 'http://host.docker.internal:11434/',
            'model' => 'gemma4',
        ])
        ->assertHasNoFormErrors();

    expect(AiProvider::sole())
        ->api_key->toBeNull()
        ->url->toBe('http://host.docker.internal:11434');
});

test('a cloud provider needs a key on create', function () {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Ohne Key',
            'vendor' => AiVendor::OpenAi->value,
            'model' => 'gpt',
        ])
        ->assertHasFormErrors(['api_key' => 'required']);

    expect(AiProvider::count())->toBe(0);
});

test('provider names are unique', function () {
    AiProvider::factory()->create(['name' => 'Doppelt']);

    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Doppelt',
            'vendor' => AiVendor::Mistral->value,
            'api_key' => 'k-1234',
            'model' => 'm',
        ])
        ->assertHasFormErrors(['name' => 'unique']);
});

test('a pasted key is stored trimmed', function () {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Trim',
            'vendor' => AiVendor::Anthropic->value,
            'api_key' => "  sk-abc\n",
            'model' => 'm',
        ])
        ->assertHasNoFormErrors();

    expect(AiProvider::sole()->api_key)->toBe('sk-abc');
});

test('editing with an empty key keeps the stored key', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-keep-1111']);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: ['model' => 'neu', 'api_key' => ''])
        ->assertHasNoFormErrors();

    $provider->refresh();
    expect($provider->api_key)->toBe('sk-keep-1111')->and($provider->model)->toBe('neu');
});

test('the vendor cannot be changed on edit', function () {
    $provider = AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: ['vendor' => AiVendor::OpenAi->value, 'model' => 'x'])
        ->assertHasNoFormErrors();

    expect($provider->refresh()->vendor)->toBe(AiVendor::Anthropic);
});

test('the key is not in the page or the edit form state', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);

    Livewire::test(ManageAiProviders::class)
        ->assertSee('••••3fa9')
        ->assertDontSee('sk-secret')
        ->mountAction(TestAction::make('edit')->table($provider))
        ->assertDontSeeHtml('sk-secret')
        ->assertSchemaStateSet(function (array $state): array {
            expect($state['api_key'] ?? null)->toBeNull()->and(json_encode($state))->not->toContain('sk-secret');

            return ['api_key' => null];
        });
});

test('activate switches the active provider and shows the badge', function () {
    $old = AiProvider::factory()->active()->create();
    $new = AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertActionHidden(TestAction::make('activate')->table($old))
        ->assertActionVisible(TestAction::make('activate')->table($new))
        ->callAction(TestAction::make('activate')->table($new))
        ->assertNotified()
        ->assertSee('Aktiv');

    expect(AiProvider::active()->is($new))->toBeTrue()->and($old->refresh()->is_active)->toBeFalse();
});

test('delete is disabled for the active provider and works for an inactive one', function () {
    $active = AiProvider::factory()->active()->create();
    $inactive = AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertActionDisabled(TestAction::make('delete')->table($active))
        ->callAction(TestAction::make('delete')->table($inactive));

    expect(AiProvider::find($inactive->id))->toBeNull()->and(AiProvider::find($active->id))->not->toBeNull();
});

test('an unreadable key shows a warning and the page and edit form still work', function () {
    $provider = AiProvider::factory()->create();
    DB::table('ai_providers')->where('id', $provider->id)->update(['api_key' => 'kaputt']);

    Livewire::test(ManageAiProviders::class)
        ->assertSee('Key nicht lesbar, bitte neu eingeben')
        ->callAction(TestAction::make('edit')->table($provider), data: ['api_key' => 'sk-neu-9999'])
        ->assertHasNoFormErrors()
        ->assertDontSee('Key nicht lesbar');

    expect($provider->refresh()->api_key)->toBe('sk-neu-9999');
});

test('the subheading names the env fallback only without an active provider', function () {
    Livewire::test(ManageAiProviders::class)->assertSee('Serverkonfiguration (.env)');

    AiProvider::factory()->active()->create();

    Livewire::test(ManageAiProviders::class)->assertDontSee('Serverkonfiguration (.env)');
});

test('the navigation entry sorts after Importe', function () {
    expect(AiProviderResource::getNavigationSort())->toBeGreaterThan(ImportResource::getNavigationSort());
});
