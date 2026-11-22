<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\ProviderTransfer;
use App\Enums\AiVendor;
use App\Enums\Language;
use App\Enums\ProviderFailureReason;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\ReembedItems;
use App\Models\AiProvider;
use App\Models\Item;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    $this->actingAs(User::factory()->admin()->create());
});

test('a member can neither see nor open the provider page', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/ai-providers')->assertForbidden();
    $this->get('/')->assertDontSee('Eigene KI');
});

function embeddedItem(): Item
{
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.1)])->saveQuietly();

    return $item;
}

test('creates a cloud provider inactive, with an encrypted key', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Mein Claude',
            'vendor' => AiVendor::Anthropic->value,
            'api_key' => 'sk-abc-1234',
            'model' => 'claude-sonnet-4-5',
        ])
        ->assertHasNoFormErrors();

    $provider = AiProvider::query()->sole();
    expect($provider->is_active)->toBeFalse()
        ->and($provider->key_hint)->toBe('1234')
        ->and($provider->api_key)->toBe('sk-abc-1234');
    expect(DB::table('ai_providers')->value('api_key'))->not->toContain('sk-abc');
});

test('an Ollama provider needs an address and no key', function (): void {
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

    expect(AiProvider::query()->sole())
        ->api_key->toBeNull()
        ->url->toBe('http://host.docker.internal:11434');
});

test('a cloud provider needs a key on create', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Ohne Key',
            'vendor' => AiVendor::OpenAi->value,
            'model' => 'gpt',
        ])
        ->assertHasFormErrors(['api_key' => 'required']);

    expect(AiProvider::query()->count())->toBe(0);
});

test('provider names are unique', function (): void {
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

test('a pasted key is stored trimmed', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Trim',
            'vendor' => AiVendor::Anthropic->value,
            'api_key' => "  sk-abc\n",
            'model' => 'm',
        ])
        ->assertHasNoFormErrors();

    expect(AiProvider::query()->sole()->api_key)->toBe('sk-abc');
});

test('editing with an empty key keeps the stored key', function (): void {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-keep-1111']);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: ['model' => 'neu', 'api_key' => ''])
        ->assertHasNoFormErrors();

    $provider->refresh();
    expect($provider->api_key)->toBe('sk-keep-1111')->and($provider->model)->toBe('neu');
});

test('the vendor cannot be changed on edit', function (): void {
    $provider = AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: ['vendor' => AiVendor::OpenAi->value, 'model' => 'x'])
        ->assertHasNoFormErrors();

    expect($provider->refresh()->vendor)->toBe(AiVendor::Anthropic);
});

test('a crafted vendor cannot give a Claude provider an embedding model', function (): void {
    $provider = AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: ['vendor' => AiVendor::OpenAi->value, 'model' => 'x', 'embedding_model' => 'evil'])
        ->assertHasNoFormErrors();

    expect($provider->refresh()->embedding_model)->toBeNull();
});

test('the key is not in the page or the edit form state', function (): void {
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

test('activate switches the active provider and shows the badge', function (): void {
    $old = AiProvider::factory()->active()->create();
    $new = AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertActionHidden(TestAction::make('activate')->table($old))
        ->assertActionVisible(TestAction::make('activate')->table($new))
        ->assertSeeHtml('tory-active-provider')
        ->callAction(TestAction::make('activate')->table($new))
        ->assertNotified()
        ->assertSeeHtml('tory-active-provider');

    expect(AiProvider::active()->is($new))->toBeTrue()->and($old->refresh()->is_active)->toBeFalse();
});

test('no card is marked active while no provider is active', function (): void {
    AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)->assertDontSeeHtml('tory-active-provider');
});

test('delete is disabled for the active provider and works for an inactive one', function (): void {
    $active = AiProvider::factory()->active()->create();
    $inactive = AiProvider::factory()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertActionDisabled(TestAction::make('delete')->table($active))
        ->callAction(TestAction::make('delete')->table($inactive));

    expect(AiProvider::query()->find($inactive->id))->toBeNull()->and(AiProvider::query()->find($active->id))->not->toBeNull();
});

test('an unreadable key shows a warning and the page and edit form still work', function (): void {
    $provider = AiProvider::factory()->create();
    DB::table('ai_providers')->where('id', $provider->id)->update(['api_key' => 'kaputt']);

    Livewire::test(ManageAiProviders::class)
        ->assertSee('Key nicht lesbar, bitte neu eingeben')
        ->callAction(TestAction::make('edit')->table($provider), data: ['api_key' => 'sk-neu-9999'])
        ->assertHasNoFormErrors()
        ->assertDontSee('Key nicht lesbar');

    expect($provider->refresh()->api_key)->toBe('sk-neu-9999');
});

test('export downloads a file with the key and warns about the plain text', function (): void {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);

    Livewire::test(ManageAiProviders::class)
        ->mountAction('export')
        ->assertMountedActionModalSee('im Klartext')
        ->callMountedAction()
        ->assertFileDownloaded(
            'tory-ai-providers-'.now()->format('Y-m-d').'.json',
            (new ProviderTransfer)->export()->toJson(),
        );

    expect($provider->fresh()->api_key)->toBe('sk-secret-3fa9');
});

test('export is disabled without providers', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->assertActionDisabled('export');
});

test('export warns when a provider with an unreadable key is left out', function (): void {
    $broken = AiProvider::factory()->create();
    AiProvider::factory()->create();
    DB::table('ai_providers')->where('id', $broken->id)->update(['api_key' => 'not-a-valid-payload']);

    Livewire::test(ManageAiProviders::class)
        ->callAction('export')
        ->assertNotified('1 Provider mit unlesbarem Key wurde nicht exportiert.');
});

test('import through the dialog creates providers inactive and reports the numbers', function (): void {
    Storage::fake('local');
    $existing = AiProvider::factory()->active()->create(['name' => 'OpenAI']);
    $file = UploadedFile::fake()->createWithContent('provider.json', json_encode([
        'format' => 'tory-ai-providers',
        'version' => 1,
        'providers' => [
            ['uuid' => $existing->id, 'name' => 'OpenAI', 'vendor' => 'openai', 'api_key' => 'sk-a', 'url' => null, 'model' => 'x'],
            ['uuid' => (string) Str::uuid(), 'name' => 'OpenAI', 'vendor' => 'openai', 'api_key' => 'sk-b', 'url' => null, 'model' => 'x'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Neu', 'vendor' => 'mistral', 'api_key' => 'sk-c', 'url' => null, 'model' => 'x'],
        ],
    ]));

    Livewire::test(ManageAiProviders::class)
        ->callAction('import', data: ['file' => $file])
        ->assertHasNoFormErrors()
        ->assertNotified('2 angelegt, 1 übersprungen, 1 umbenannt');

    expect(AiProvider::query()->count())->toBe(3)
        ->and(AiProvider::active()?->is($existing))->toBeTrue()
        ->and(AiProvider::query()->where('name', 'OpenAI (Import)')->exists())->toBeTrue();
});

test('an invalid import file shows the error and changes nothing', function (): void {
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent('provider.json', '{"providers": [sk-secret');

    Livewire::test(ManageAiProviders::class)
        ->callAction('import', data: ['file' => $file])
        ->assertNotified('Import fehlgeschlagen');

    expect(AiProvider::query()->count())->toBe(0);
});

test('the uploaded file is deleted after the import, also after a failed one', function (string $content): void {
    $uploads = Storage::fake(FileUploadConfiguration::disk());
    $file = UploadedFile::fake()->createWithContent('provider.json', $content);

    Livewire::test(ManageAiProviders::class)
        ->callAction('import', data: ['file' => $file]);

    $remaining = array_map($uploads->get(...), $uploads->allFiles());

    expect($remaining)->not->toContain($content);
})->with([
    'invalid' => ['{"providers": [sk-secret'],
    'valid' => [json_encode(['format' => 'tory-ai-providers', 'version' => 1, 'providers' => []])],
]);

test('the navigation entry sorts after Importe', function (): void {
    expect(AiProviderResource::getNavigationSort())->toBeGreaterThan(ImportResource::getNavigationSort());
});

test('the page title is Eigene KI', function (): void {
    expect(Livewire::test(ManageAiProviders::class)->instance()->getTitle())->toBe('Eigene KI');
});

test('the model field suggests fetched models', function (): void {
    Http::fake(['http://ollama:11434/api/tags' => Http::response(['models' => [['name' => 'b:1'], ['name' => 'a:1']]])]);

    Livewire::test(ManageAiProviders::class)
        ->mountAction('create')
        ->fillForm(['vendor' => AiVendor::Ollama->value, 'url' => 'http://ollama:11434'])
        ->assertSchemaComponentExists('model', checkComponentUsing: fn (TextInput $field): bool => $field->getDatalistOptions() === ['a:1', 'b:1']
            && $field->getChildSchema($field::BELOW_CONTENT_SCHEMA_KEY) === null);
});

test('editing with an empty key fetches with the stored key', function (): void {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-stored-7777']);
    Http::fake(['https://api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-x']]])]);

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('edit')->table($provider))
        ->assertSchemaComponentExists('model', checkComponentUsing: fn (TextInput $field): bool => $field->getDatalistOptions() === ['claude-x']);

    Http::assertSent(fn (Request $request): bool => $request->header('x-api-key') === ['sk-stored-7777']);
});

test('a failed fetch shows a hint and a free-text model still saves', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    Livewire::test(ManageAiProviders::class)
        ->mountAction('create')
        ->fillForm(['vendor' => AiVendor::Ollama->value, 'url' => 'http://ollama:11434'])
        ->assertSchemaComponentExists('model', checkComponentUsing: fn (TextInput $field): bool => $field->getChildSchema($field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0]->getContent() === 'Modelle konnten nicht geladen werden. Du kannst den Namen auch eintippen.')
        ->fillForm(['name' => 'Frei', 'model' => 'mein-modell'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(AiProvider::query()->sole()->model)->toBe('mein-modell');
});

test('testing an inactive provider uses it and leaves it inactive', function (): void {
    InventoryExtractor::fake([['title' => 'Rotes Quadrat', 'description' => 'Rot.']]);
    $inactive = AiProvider::factory()->create(['name' => 'Reserve']);
    $before = DB::table('ai_providers')->where('id', $inactive->id)->first();

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('test')->table($inactive))
        ->assertNotified('Reserve hat das Testbild beantwortet.');

    InventoryExtractor::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 1);
    expect(DB::table('ai_providers')->where('id', $inactive->id)->first())->toEqual($before)
        ->and($inactive->refresh()->is_active)->toBeFalse();
});

test('a failed test notifies with the reason', function (): void {
    Http::fake(['*' => Http::response([], 401)]);
    $provider = AiProvider::factory()->create(['name' => 'Claude']);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('test')->table($provider))
        ->assertNotified(
            Notification::make()->title('Test fehlgeschlagen')->body('Claude hat den API-Key abgelehnt.')->danger()
        );
});

test('a failed probe keeps the dialog closed and changes nothing', function (): void {
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);
    $provider = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('activateForSearch')->table($provider))
        ->assertActionNotMounted()
        ->assertNotified();

    expect($provider->fresh()->is_active_for_search)->toBeFalse();
});

test('confirming the dialog switches and queues the recomputation', function (): void {
    Queue::fake();
    Embeddings::fake();
    $provider = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('activateForSearch')->table($provider));

    expect(AiProvider::activeForSearch()->is($provider))->toBeTrue();
    Queue::assertPushed(ReembedItems::class);
});

test('a failing probe at confirm changes nothing and queues nothing', function (): void {
    $item = embeddedItem();
    $probes = 0;
    Embeddings::fake(function () use (&$probes): array {
        $probes++;

        return [array_fill(0, $probes === 1 ? 1024 : 768, 0.1)];
    });
    $provider = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('activateForSearch')->table($provider))
        ->assertActionMounted(TestAction::make('activateForSearch')->table($provider))
        ->callMountedAction()
        ->assertNotified('Test fehlgeschlagen')
        ->assertActionNotMounted();

    expect($probes)->toBe(2)
        ->and($provider->fresh()->is_active_for_search)->toBeFalse()
        ->and($item->fresh()->embedding)->not->toBeNull();
    Queue::assertNotPushed(ReembedItems::class);
});

test('a confirm request without a mount still probes', function (): void {
    $item = embeddedItem();
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);
    $provider = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->set('mountedActions', [['name' => 'activateForSearch', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => $provider->getKey()]]])
        ->call('callMountedAction')
        ->assertNotified('Test fehlgeschlagen');

    expect($provider->fresh()->is_active_for_search)->toBeFalse()
        ->and($item->fresh()->embedding)->not->toBeNull();
    Queue::assertNotPushed(ReembedItems::class);
});

test('a model changed between mount and confirm is probed and switches only if that passes', function (int $dimensions, bool $switches): void {
    $item = embeddedItem();
    $probedModels = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$probedModels, $dimensions): array {
        $probedModels[] = $prompt->model;

        return [array_fill(0, $prompt->model === 'ungeprueft' ? $dimensions : 1024, 0.1)];
    });
    $provider = AiProvider::factory()->embedding('gut')->create();

    $component = Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('activateForSearch')->table($provider));

    AiProvider::query()->whereKey($provider->getKey())->update(['embedding_model' => 'ungeprueft']);

    $component->callMountedAction();

    expect($probedModels)->toBe(['gut', 'ungeprueft'])
        ->and($provider->fresh()->is_active_for_search)->toBe($switches)
        ->and($provider->fresh()->embedding_model)->toBe('ungeprueft')
        ->and($item->fresh()->embedding === null)->toBe($switches);
    $switches ? Queue::assertPushed(ReembedItems::class) : Queue::assertNotPushed(ReembedItems::class);
})->with([
    'probe passes' => [1024, true],
    'probe fails' => [768, false],
]);

test('a model emptied between mount and confirm does not switch', function (): void {
    $item = embeddedItem();
    Embeddings::fake();
    $provider = AiProvider::factory()->embedding()->create();

    $component = Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('activateForSearch')->table($provider));

    AiProvider::query()->whereKey($provider->getKey())->update(['embedding_model' => null]);

    $component->callMountedAction();

    expect($provider->fresh()->is_active_for_search)->toBeFalse()
        ->and($item->fresh()->embedding)->not->toBeNull();
    Queue::assertNotPushed(ReembedItems::class);
});

test('the activation dialog names both models and the number of items', function (): void {
    Embeddings::fake();
    AiProvider::factory()->activeForSearch()->create(['embedding_model' => 'altes-modell']);
    $new = AiProvider::factory()->embedding('neues-modell')->create();
    Queue::fake();
    Item::factory()->count(2)->create();

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('activateForSearch')->table($new))
        ->assertActionMounted(TestAction::make('activateForSearch')->table($new))
        ->assertMountedActionModalSee(['altes-modell', 'neues-modell', '2 Gegenstände', 'unvollständig', 'Kosten']);
});

test('the activation dialog shows none as the current model when no provider is active for search', function (): void {
    Embeddings::fake();
    $new = AiProvider::factory()->embedding('neues-modell')->create();

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('activateForSearch')->table($new))
        ->assertMountedActionModalSee(['keins', 'neues-modell'])
        ->assertMountedActionModalDontSee('.env');
});

test('activate for search is hidden without an embedding model and for the active one', function (): void {
    $withoutModel = AiProvider::factory()->create();
    $active = AiProvider::factory()->activeForSearch()->create();
    $available = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertActionHidden(TestAction::make('activateForSearch')->table($withoutModel))
        ->assertActionHidden(TestAction::make('activateForSearch')->table($active))
        ->assertActionVisible(TestAction::make('activateForSearch')->table($available));
});

test('activate is hidden without a model for object recognition', function (): void {
    $provider = AiProvider::factory()->embedding()->create(['model' => null]);

    Livewire::test(ManageAiProviders::class)
        ->assertActionHidden(TestAction::make('activate')->table($provider));
});

test('a provider needs at least one model', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Ohne Modell',
            'vendor' => AiVendor::OpenAi->value,
            'api_key' => 'sk-1234',
            'model' => '',
            'embedding_model' => '',
        ])
        ->assertHasFormErrors(['model' => 'required', 'embedding_model' => 'required']);

    expect(AiProvider::query()->count())->toBe(0);
});

test('one model is enough and the other one saves as null', function (array $models, ?string $model, ?string $embeddingModel): void {
    Livewire::test(ManageAiProviders::class)
        ->callAction('create', data: [
            'name' => 'Ein Modell',
            'vendor' => AiVendor::OpenAi->value,
            'api_key' => 'sk-1234',
            ...$models,
        ])
        ->assertHasNoFormErrors();

    expect(AiProvider::query()->sole())
        ->model->toBe($model)
        ->embedding_model->toBe($embeddingModel);
})->with([
    'object recognition only' => [['model' => 'gpt', 'embedding_model' => '  '], 'gpt', null],
    'embedding only' => [['model' => '', 'embedding_model' => ' text-embedding-3-small '], null, 'text-embedding-3-small'],
]);

test('claude needs a model and offers no embedding fields', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->mountAction('create')
        ->fillForm(['name' => 'Claude', 'vendor' => AiVendor::Anthropic->value, 'api_key' => 'sk-1234', 'model' => ''])
        ->assertFormFieldHidden('embedding_model')
        ->assertFormFieldHidden('search_min_similarity')
        ->callMountedAction()
        ->assertHasFormErrors(['model' => 'required']);
});

test('an embedding model typed before switching the vendor to claude is not saved', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->mountAction('create')
        ->fillForm([
            'name' => 'Claude',
            'vendor' => AiVendor::OpenAi->value,
            'api_key' => 'sk-1234',
            'embedding_model' => 'text-embedding-3-small',
        ])
        ->fillForm(['vendor' => AiVendor::Anthropic->value, 'model' => 'claude-x'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(AiProvider::query()->sole()->embedding_model)->toBeNull();
});

test('a model is still required for claude when an embedding model was typed before switching the vendor', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->mountAction('create')
        ->fillForm([
            'name' => 'Claude',
            'vendor' => AiVendor::OpenAi->value,
            'api_key' => 'sk-1234',
            'embedding_model' => 'text-embedding-3-small',
        ])
        ->fillForm(['vendor' => AiVendor::Anthropic->value, 'model' => ''])
        ->callMountedAction()
        ->assertHasFormErrors(['model' => 'required']);
});

test('the minimum similarity must lie between 0 and 1 and defaults to 0.5', function (): void {
    $component = Livewire::test(ManageAiProviders::class)
        ->mountAction('create')
        ->fillForm(['name' => 'Suche', 'vendor' => AiVendor::OpenAi->value, 'api_key' => 'sk-1234'])
        ->assertFormFieldHidden('search_min_similarity')
        ->fillForm(['embedding_model' => 'text-embedding-3-small'])
        ->assertFormFieldVisible('search_min_similarity')
        ->assertSchemaStateSet(['search_min_similarity' => 0.5]);

    foreach ([1.5, -0.1, ''] as $invalid) {
        $component->fillForm(['search_min_similarity' => $invalid])
            ->callMountedAction()
            ->assertHasFormErrors(['search_min_similarity']);
    }

    expect(AiProvider::query()->count())->toBe(0);

    $component->fillForm(['search_min_similarity' => 0.42])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(AiProvider::query()->sole()->search_min_similarity)->toBe(0.42);
});

test('the minimum similarity is prefilled when an existing provider gets its first embedding model', function (): void {
    $provider = AiProvider::factory()->create(['vendor' => AiVendor::OpenAi]);

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('edit')->table($provider))
        ->fillForm(['embedding_model' => 'text-embedding-3-small'])
        ->assertSchemaStateSet(['search_min_similarity' => 0.5])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($provider->fresh()->search_min_similarity)->toBe(0.5);
});

test('the role model cannot be emptied', function (): void {
    $recognition = AiProvider::factory()->embedding()->active()->create(['model' => 'gpt']);
    $search = AiProvider::factory()->activeForSearch()->create(['model' => 'gpt']);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($recognition), data: ['model' => ''])
        ->assertHasFormErrors(['model' => 'required']);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($search), data: ['embedding_model' => ''])
        ->assertHasFormErrors(['embedding_model' => 'required'])
        ->assertHasNoFormErrors(['confirm_search_switch']);

    expect($recognition->fresh()->model)->toBe('gpt')
        ->and($search->fresh()->embedding_model)->toBe('text-embedding-3-small');
});

test('a changed embedding model on the active provider requires the checkbox', function (): void {
    Queue::fake();
    Embeddings::fake();
    $provider = AiProvider::factory()->activeForSearch()->create();

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('edit')->table($provider))
        ->assertFormFieldHidden('confirm_search_switch')
        ->fillForm(['embedding_model' => 'neues-modell'])
        ->assertFormFieldVisible('confirm_search_switch')
        ->assertSchemaComponentExists('confirm_search_switch', checkComponentUsing: fn (Checkbox $field): bool => Str::containsAll((string) $field->getLabel(), ['text-embedding-3-small', 'neues-modell', '0 Gegenstände', 'unvollständig', 'Kosten']))
        ->callMountedAction()
        ->assertHasFormErrors(['confirm_search_switch' => 'accepted']);

    expect($provider->fresh()->embedding_model)->toBe('text-embedding-3-small');
    Queue::assertNotPushed(ReembedItems::class);
});

test('a confirmed model change probes and switches', function (): void {
    $item = embeddedItem();
    $usedKeys = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$usedKeys): array {
        $usedKeys[] = [$prompt->provider->providerCredentials()['key'], $prompt->model];

        return [array_fill(0, 1024, 0.1)];
    });
    $provider = AiProvider::factory()->activeForSearch()->create(['search_failure' => 'Alt.']);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: [
            'name' => 'Neuer Name',
            'api_key' => 'sk-neu-5678',
            'embedding_model' => ' neues-modell ',
            'confirm_search_switch' => true,
        ])
        ->assertHasNoFormErrors();

    Queue::assertPushed(ReembedItems::class);
    expect($usedKeys)->toBe([['sk-neu-5678', 'neues-modell']])
        ->and($item->fresh()->embedding)->toBeNull();
    expect($provider->fresh())
        ->name->toBe('Neuer Name')
        ->api_key->toBe('sk-neu-5678')
        ->embedding_model->toBe('neues-modell')
        ->searchFailureMessage()->toBeNull()
        ->is_active_for_search->toBeTrue();
});

test('a failed probe on edit saves nothing, not even other fields', function (): void {
    $item = embeddedItem();
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);
    $provider = AiProvider::factory()->activeForSearch()->create(['name' => 'Alter Name', 'api_key' => 'sk-alt-1111']);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: [
            'name' => 'Neuer Name',
            'api_key' => 'sk-neu-5678',
            'embedding_model' => 'neues-modell',
            'confirm_search_switch' => true,
        ])
        ->assertNotified()
        ->assertActionMounted(TestAction::make('edit')->table($provider));

    Queue::assertNotPushed(ReembedItems::class);
    expect($item->fresh()->embedding)->not->toBeNull();
    expect($provider->fresh())
        ->name->toBe('Alter Name')
        ->api_key->toBe('sk-alt-1111')
        ->embedding_model->toBe('text-embedding-3-small');
});

test('editing the key, address or threshold does not recompute', function (array $attributes, array $data): void {
    $item = embeddedItem();
    $provider = AiProvider::factory()->activeForSearch()->create($attributes);

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('edit')->table($provider), data: $data)
        ->assertHasNoFormErrors();

    Queue::assertNotPushed(ReembedItems::class);
    expect($item->fresh()->embedding)->not->toBeNull();

    foreach ($data as $field => $value) {
        expect($provider->fresh()->{$field})->toBe($value);
    }
})->with([
    'key' => [[], ['api_key' => 'sk-neu-5678']],
    'address' => [['vendor' => AiVendor::Ollama, 'api_key' => null, 'url' => 'http://alt:11434'], ['url' => 'http://neu:11434']],
    'threshold' => [[], ['search_min_similarity' => 0.7]],
    'name' => [[], ['name' => 'Neuer Name']],
    'model for object recognition' => [[], ['model' => 'gpt-neu']],
]);

test('editing an inactive provider\'s embedding model does not recompute', function (): void {
    $item = embeddedItem();
    $provider = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('edit')->table($provider))
        ->fillForm(['embedding_model' => 'neues-modell'])
        ->assertFormFieldHidden('confirm_search_switch')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    Queue::assertNotPushed(ReembedItems::class);
    expect($item->fresh()->embedding)->not->toBeNull()
        ->and($provider->fresh()->embedding_model)->toBe('neues-modell');
});

test('delete is disabled for the provider active for search', function (): void {
    $provider = AiProvider::factory()->activeForSearch()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertActionDisabled(TestAction::make('delete')->table($provider));
});

test('the card shows both models and a badge per role', function (): void {
    AiProvider::factory()->activeForSearch()->active()->create(['model' => 'gpt-x', 'embedding_model' => 'embed-x']);

    Livewire::test(ManageAiProviders::class)
        ->assertSee(['Bilderkennung: gpt-x', 'Embedding: embed-x', 'Suche aktiv'])
        ->assertSeeHtml('tory-active-provider');
});

test('a card without a model for a role shows no prefix for it', function (): void {
    AiProvider::factory()->embedding()->create(['model' => null]);

    Livewire::test(ManageAiProviders::class)
        ->assertDontSee('Bilderkennung:')
        ->assertDontSeeHtml('tory-active-provider');
});

test('a card active for search is highlighted', function (): void {
    AiProvider::factory()->activeForSearch()->create();

    Livewire::test(ManageAiProviders::class)->assertSeeHtml('tory-active-provider');
});

test('the card shows progress and the stored failure', function (): void {
    embeddedItem();
    Item::factory()->create();
    AiProvider::factory()->activeForSearch()->create(['search_failure' => 'OpenAI ist nicht erreichbar.']);

    Livewire::test(ManageAiProviders::class)
        ->assertSee('Suche wird neu aufgebaut: 1 von 2')
        ->assertSee('OpenAI ist nicht erreichbar. Es wird stündlich erneut versucht.');
});

test('progress and failure appear only on the provider active for search', function (): void {
    Queue::fake();
    Item::factory()->create();
    AiProvider::factory()->embedding()->create(['search_failure' => 'Alte Meldung.']);

    Livewire::test(ManageAiProviders::class)
        ->assertDontSee('Suche wird neu aufgebaut')
        ->assertDontSee('Alte Meldung.');
});

test('no progress is shown when every item has an embedding', function (): void {
    embeddedItem();
    AiProvider::factory()->activeForSearch()->create();

    Livewire::test(ManageAiProviders::class)->assertDontSee('Suche wird neu aufgebaut');
});

test('the page polls only while the provider active for search has items to embed', function (): void {
    $pollingInterval = fn (): ?string => Livewire::test(ManageAiProviders::class)->instance()->getTable()->getPollingInterval();

    Queue::fake();
    $item = Item::factory()->create();

    expect($pollingInterval())->toBeNull();

    AiProvider::factory()->activeForSearch()->create();

    expect($pollingInterval())->toBe('5s');

    $item->forceFill(['embedding' => array_fill(0, 1024, 0.1)])->saveQuietly();

    expect($pollingInterval())->toBeNull();
});

test('the subheading names the roles without a provider', function (): void {
    Livewire::test(ManageAiProviders::class)
        ->assertSee('Noch keine KI für die Bilderkennung und die Suche aktiv.')
        ->assertDontSee('.env');

    $recognition = AiProvider::factory()->active()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertSee('Noch keine KI für die Suche aktiv.')
        ->assertDontSee('Noch keine KI für die Bilderkennung')
        ->assertDontSee('.env');

    AiProvider::factory()->activeForSearch()->create();

    Livewire::test(ManageAiProviders::class)
        ->assertDontSee('Noch keine KI')
        ->assertDontSee('.env');

    $recognition->forceFill(['is_active' => false])->save();

    Livewire::test(ManageAiProviders::class)
        ->assertSee('Noch keine KI für die Bilderkennung aktiv.')
        ->assertDontSee('und die Suche')
        ->assertDontSee('.env');
});

test('an English user sees the provider page in English', function (): void {
    $user = User::factory()->admin()->create();
    $user->forceFill(['locale' => Language::English])->save();
    app()->setLocale('en');
    $this->actingAs($user);

    Livewire::test(ManageAiProviders::class)
        ->assertSee('AI providers')
        ->assertSee('No AI is active for image recognition and search yet.')
        ->assertDontSee('.env')
        ->assertDontSee('Eigene KI')
        ->assertDontSee('Noch keine KI');
});

test('an English user sees the stored search failure in English', function (): void {
    $user = User::factory()->admin()->create();
    $user->forceFill(['locale' => Language::English])->save();
    app()->setLocale('en');
    $this->actingAs($user);
    AiProvider::factory()->activeForSearch()->create(['name' => 'OpenAI', 'search_failure_reason' => ProviderFailureReason::KeyRejected]);

    Livewire::test(ManageAiProviders::class)
        ->assertSee('OpenAI rejected the API key. It will be retried every hour.')
        ->assertDontSee('abgelehnt');
});
