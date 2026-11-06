# AI Provider Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A panel page "Eigene KI" where the user manages several AI providers with credentials and model, activates one, tests it, and object recognition runs on the active one.

**Architecture:** An `AiProvider` model holds vendor, encrypted key, address and model, with at most one active row enforced by a partial unique index. `InventoryExtractor` builds its `laravel/ai` provider at runtime from that row (`Ai::build()`), read fresh for every job, and falls back to `ai.inventory.*` when none is active. The page is a Filament simple resource whose table renders as a card grid. One classifier turns vendor errors into German reasons for the import page and the test action.

**Tech Stack:** Laravel 13, Filament 5, `laravel/ai`, Postgres 18, Pest. Everything runs in Docker; see `CLAUDE.md`.

**Spec:** `docs/superpowers/specs/2026-11-06-ai-provider-settings-design.md`

## Global Constraints

- Vendors in the UI: Ollama, Claude (driver `anthropic`), OpenAI, Mistral. No others.
- All UI copy is German. Page title and navigation label: "Eigene KI".
- `ExtractImport` keeps `$tries = 1` and `$timeout = 200`. `InventoryExtractor::timeout()` stays 180.
- Embeddings and search stay untouched: `ItemEmbedder` and `ai.inventory.embedding_*` do not change.
- Nothing switches the active provider on its own. There is no failover.
- Keys must not appear in plain text in the UI, Livewire state, logs, import errors, notifications or exception reports.
- With no active provider, extraction behaves exactly as today, and existing tests in `tests/Feature/Ai/` and `tests/Feature/Jobs/ExtractImportTest.php` keep passing unchanged.
- No new Composer or npm dependencies. No test talks to a real vendor.
- Never assert a literal id. Run commands in the `app` container. Run Pint (`./vendor/bin/pint --dirty --format agent`) and PHPStan before each commit.
- Before writing tests read the `testing-best-practices` skill; before Filament code read `filament-development`.
- End every commit message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Work on branch `ai-provider-settings`. The first commit includes the spec and this plan.

## Review Focus

1. A key pasted with surrounding whitespace or a newline is stored trimmed, so the vendor does not reject it. Test in Task 3.
2. A vendor error body echoing the key: the key is absent from `imports.error` and from what `report()` receives. Test in Task 5.
3. A provider renamed or deleted after an import failed: the import page still names the provider used at the time and its link still works. Test in Task 5.
4. Retry after fixing the provider: the old reason disappears at once and the retry runs on the provider active now. Test in Task 5.
5. A model list endpoint answering 200 with an unexpected JSON shape: no crash, no suggestions, free text still saves. Test in Task 4.

---

### Task 1: `AiProvider` model

**Files:**
- Create: `app/Enums/AiVendor.php`, `app/Models/AiProvider.php`, `database/factories/AiProviderFactory.php`, migration `create_ai_providers_table`
- Test: `tests/Feature/Models/AiProviderTest.php`

**Interfaces:**
- Produces: `enum AiVendor: string implements HasLabel` with cases `Ollama='ollama'`, `Anthropic='anthropic'` (label "Claude"), `OpenAi='openai'` (label "OpenAI"), `Mistral='mistral'`, and `requiresKey(): bool` (false only for Ollama).
- Produces: `AiProvider` with attributes `name`, `vendor` (cast `AiVendor`), `api_key` (cast `encrypted`, in `$hidden`), `key_hint`, `url`, `model`, `is_active` (cast bool) and:
  - `static active(): ?self`
  - `activate(): void`
  - `readableKey(): ?string`: the key, or null when absent or undecryptable
  - `hasUnreadableKey(): bool`: a key is stored but cannot be decrypted
  - `scrub(string $text): string`: replaces the readable key in `$text` with `[Key]`
- Produces: factory defaulting to Anthropic with a key and model, states `ollama()` and `active()`.

- [ ] **Step 1: Create branch `ai-provider-settings`.** Run `git switch -c ai-provider-settings`.

- [ ] **Step 2: Write the failing tests**

```php
test('the key is encrypted at rest and hidden from arrays', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);

    expect(DB::table('ai_providers')->value('api_key'))->not->toContain('sk-secret')
        ->and($provider->api_key)->toBe('sk-secret-3fa9')
        ->and($provider->toArray())->not->toHaveKey('api_key')
        ->and($provider->attributesToArray())->not->toHaveKey('api_key');
});

test('saving a key stores its last four characters as the hint', ...);
// create key 'sk-secret-3fa9' -> key_hint '3fa9'; update key 'sk-new-81c2' -> '81c2';
// updating only the model leaves the hint unchanged

test('activating leaves exactly one active provider', ...);
// A active, B->activate(): A inactive, B active, AiProvider::active() is B

test('the database refuses a second active provider', ...);
// direct DB::table insert/update making a second row active throws QueryException

test('the active provider cannot be deleted', ...);
// $active->delete() is false and the row remains; an inactive one deletes

test('an undecryptable key does not throw', ...);
// DB::table update api_key to 'not-a-valid-payload' on a saved row:
// fresh()->readableKey() null, hasUnreadableKey() true, key_hint still present,
// attributesToArray() does not throw

test('scrub removes the key from text and leaves text unchanged without a key', ...);
```

- [ ] **Step 3: Run tests to verify they fail.** Run `docker compose exec app php artisan test --compact tests/Feature/Models/AiProviderTest.php`. Expected: FAIL, class not found.

- [ ] **Step 4: Implement migration, enum, model and factory**

Table `ai_providers`: `id`, `name` string unique, `vendor` string, `api_key` text nullable, `key_hint` string nullable, `url` string nullable, `model` string, `is_active` boolean default false, timestamps. Add the index with `DB::statement('CREATE UNIQUE INDEX ai_providers_one_active ON ai_providers (is_active) WHERE is_active')`.

`booted()`: a `saving` listener sets `key_hint` from `api_key` when `api_key` is dirty; a `deleting` listener returns false when `is_active`.

`activate()` runs in one transaction: first `lockForUpdate()` on all rows, so a concurrent activation waits and then sees committed state, then clears the active row, then sets this row. Clear before set, because the index checks per statement.

- [ ] **Step 5: Run tests to verify they pass.** Same command. Expected: PASS.

- [ ] **Step 6: Commit** the code, spec and plan: `git commit -m "Add the AI provider model with one active provider"`.

---

### Task 2: Extraction runs on the active provider

**Files:**
- Modify: `app/Models/AiProvider.php`, `app/Ai/Agents/InventoryExtractor.php`, `app/Jobs/ExtractImport.php`
- Test: `tests/Feature/Ai/InventoryExtractorTest.php`, `tests/Feature/Jobs/ExtractImportTest.php`

**Interfaces:**
- Consumes: `AiProvider` from Task 1.
- Produces: `AiProvider::toLaravelProvider(): Laravel\Ai\Providers\Provider`.
- Produces: `InventoryExtractor::__construct(public ?AiProvider $aiProvider = null)`, so `InventoryExtractor::make(aiProvider: $provider)` works; `provider(): Provider|string`; `model(): ?string`.
- Produces: `InventoryExtractor::extract(array $attachments, ?int $timeout = null): array{title: string, description: string}`, which sends the existing prompt `'Analysiere die Fotos und liefere Titel und Beschreibung.'` and throws `RuntimeException('Das Modell hat keine vollständige Antwort geliefert.')` on an incomplete answer. The validation moves here from `ExtractImport`.

- [ ] **Step 1: Write the failing tests**

```php
// InventoryExtractorTest.php
test('builds its provider from the given AI provider', function () {
    $aiProvider = AiProvider::factory()->create([
        'vendor' => AiVendor::Mistral, 'api_key' => 'mk-1', 'model' => 'pixtral-x',
    ]);
    $agent = new InventoryExtractor($aiProvider);

    expect($agent->provider()->driver())->toBe('mistral')
        ->and($agent->provider()->providerCredentials()['key'])->toBe('mk-1')
        ->and($agent->model())->toBe('pixtral-x');
});

test('an Ollama provider uses its stored address', ...);
// resulting provider's additionalConfiguration / url is the stored url, no key required

// ExtractImportTest.php
test('runs on the active provider', function () {
    InventoryExtractor::fake([['title' => 'A', 'description' => 'B']]);
    AiProvider::factory()->active()->create(['vendor' => AiVendor::OpenAi, 'model' => 'gpt-x']);

    (new ExtractImport(Import::factory()->create()))->handle();

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->provider->driver() === 'openai' && $prompt->model === 'gpt-x');
});

test('reads the active provider fresh for every job', ...);
// same process: handle() with A active, B->activate(), handle() again; second prompt used B

test('falls back to the inventory config without an active provider', ...);
// inactive provider exists; config ai.inventory.provider = 'ollama'; prompt provider driver 'ollama'
```

- [ ] **Step 2: Run tests to verify they fail.** Run `docker compose exec app php artisan test --compact tests/Feature/Ai/InventoryExtractorTest.php tests/Feature/Jobs/ExtractImportTest.php`. Expected: new tests FAIL.

- [ ] **Step 3: Implement**

`toLaravelProvider()` calls `Laravel\Ai\Ai::build([...config("ai.providers.{$vendor}"), 'key' => ..., 'url' => ...])`, setting `url` only for Ollama and `key` to `''` for Ollama. Set no `name`, because a vendor name throws in `build()`. Do not catch `DecryptException`: an unreadable key must surface when prompted; read `api_key` directly here.

In `provider()`, return `$this->aiProvider?->toLaravelProvider()` when set, else today's config logic; same for `model()`. `ExtractImport::handle()` resolves `AiProvider::active()` at its start, inside the existing `try`, and calls `InventoryExtractor::make(aiProvider: $aiProvider)->extract($attachments)`. Do not store the provider on the job's properties or anywhere process-wide.

- [ ] **Step 4: Run tests to verify they pass.** Same command. Expected: all PASS, including existing tests unchanged.

- [ ] **Step 5: Commit:** `git commit -m "Run extraction on the active AI provider"`.

---

### Task 3: The "Eigene KI" page

**Files:**
- Create: `app/Filament/Resources/AiProviders/AiProviderResource.php`, `Pages/ManageAiProviders.php`, `Schemas/AiProviderForm.php`, `Tables/AiProvidersTable.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/Panel/AiProviderResourceTest.php`

**Interfaces:**
- Consumes: `AiProvider`, `AiVendor` from Task 1.
- Produces: `AiProviderResource::getUrl('index')` at slug `eigene-ki`; `ManageAiProviders` (`ManageRecords`); table actions named `activate`, `edit`, `delete`; header action `create`; `AiProviderForm::configure(Schema $schema): Schema`. Later tasks add to the form and table.

- [ ] **Step 1: Write the failing tests**, with `actingAs` a verified user in `beforeEach`.

```php
test('creates a cloud provider inactive, with an encrypted key', ...);
// callAction('create', data: name, vendor Anthropic, api_key, model) -> row exists, is_active false, key_hint set

test('an Ollama provider needs an address and no key', ...);
// url cleared -> assertHasFormErrors(['url' => 'required']); with url -> saved with api_key null

test('a cloud provider needs a key on create', ...);

test('provider names are unique', ...);

test('a pasted key is stored trimmed', ...);
// api_key "  sk-abc\n" -> $provider->api_key === 'sk-abc'

test('editing with an empty key keeps the stored key', ...);
// callAction(TestAction::make('edit')->table($provider), data: ['model' => 'neu', 'api_key' => '']) -> key unchanged, model 'neu'

test('the vendor cannot be changed on edit', ...);
// submitting another vendor leaves it unchanged

test('the key is not in the page or the edit form state', function () {
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-3fa9']);

    Livewire::test(ManageAiProviders::class)
        ->assertSee('••••3fa9')
        ->assertDontSee('sk-secret')
        ->mountAction(TestAction::make('edit')->table($provider))
        ->assertDontSeeHtml('sk-secret')
        ->assertSchemaStateSet(fn (array $state) => expect($state['api_key'] ?? null)->toBeNull() ?? []);
});

test('activate switches the active provider and shows the badge', ...);

test('delete is disabled for the active provider and works for an inactive one', ...);
// assertActionDisabled(TestAction::make('delete')->table($active)); callAction on the inactive removes it

test('an unreadable key shows a warning and the page and edit form still work', ...);
// DB-level broken payload; assertSee('Key nicht lesbar, bitte neu eingeben'); edit with a new key saves and warning goes

test('the subheading names the env fallback only without an active provider', ...);
// assertSee('Serverkonfiguration (.env)') with none active; assertDontSee with one active

test('the navigation entry sorts after Importe', ...);
// AiProviderResource::getNavigationSort() > ImportResource::getNavigationSort()
```

Adjust the state assertion to what the installed Filament testing API offers; the requirement is that the mounted form state holds no key.

- [ ] **Step 2: Run tests to verify they fail.** Run `docker compose exec app php artisan test --compact tests/Feature/Panel/AiProviderResourceTest.php`. Expected: FAIL, class not found.

- [ ] **Step 3: Implement the resource and form**

Follow `StorageLocationResource` properties: slug `eigene-ki`, `$modelLabel` 'Provider', `$pluralModelLabel` 'Provider', navigation label 'Eigene KI', title 'Eigene KI', sort 4, icon `Heroicon::OutlinedCpuChip`. Only an `index` page. Header `CreateAction` labelled "Provider anlegen", `createAnother(false)`.

Form fields:
- `name`: required, `unique(ignoreRecord: true)`, max 255
- `vendor`: `Select` from the enum, required, `live()`, disabled and not dehydrated on edit
- `api_key`: `TextInput::password()`, not revealable, visible when `vendor->requiresKey()`, required only on create, `dehydrateStateUsing(trim)`, `dehydrated(fn ($state) => filled($state))`, on edit placeholder `••••{hint}`, `live(onBlur: true)`
- `url`: visible for Ollama, required, `url()`, default `http://host.docker.internal:11434`, trailing slash stripped on dehydrate, `live(onBlur: true)`
- `model`: `TextInput`, required, max 255

- [ ] **Step 4: Implement the card table**

`AiProvidersTable`: `Stack` of text columns: name (bold), vendor, model, connection (Ollama: url; others: `••••{key_hint}`, or the danger text "Key nicht lesbar, bitte neu eingeben" when `hasUnreadableKey()`), and an "Aktiv" success badge shown only when active. `contentGrid(['md' => 2, 'xl' => 3])`, `paginated(false)`, `defaultSort('name')`, `recordClasses` giving the active record the class `tory-active-provider`.

Record actions: `activate` labelled "Aktivieren", hidden when active, success notification; `EditAction` "Bearbeiten"; `DeleteAction` "Löschen", disabled with tooltip "Erst einen anderen Provider aktivieren" when active.

In `AdminPanelProvider` add a `renderHook(PanelsRenderHook::STYLES_AFTER, ...)` with one inline `<style>` giving `.tory-active-provider` a 2px success-coloured outline via Filament's CSS variable, because the precompiled theme has no arbitrary utilities.

`ManageAiProviders::getSubheading()` returns "Aktuell gilt die Serverkonfiguration (.env)." when no provider is active, else null.

- [ ] **Step 5: Run tests to verify they pass.** Same command. Expected: PASS.

- [ ] **Step 6: Commit:** `git commit -m "Add the Eigene KI page for managing AI providers"`.

---

### Task 4: Model suggestions

**Files:**
- Create: `app/Ai/ProviderModels.php`
- Modify: `app/Filament/Resources/AiProviders/Schemas/AiProviderForm.php`
- Test: `tests/Feature/Ai/ProviderModelsTest.php`, `tests/Feature/Panel/AiProviderResourceTest.php`

**Interfaces:**
- Consumes: `AiVendor`, form from Task 3.
- Produces: `ProviderModels::for(AiVendor $vendor, ?string $key, ?string $url): ?array`: sorted `list<string>` of model names, an empty list when the needed credential is missing, null when the fetch failed.

- [ ] **Step 1: Write the failing tests**

```php
test('lists Ollama models from its tags endpoint', function () {
    Http::fake(['http://ollama:11434/api/tags' => Http::response(['models' => [['name' => 'b:1'], ['name' => 'a:1']]])]);

    expect(ProviderModels::for(AiVendor::Ollama, null, 'http://ollama:11434'))->toBe(['a:1', 'b:1']);
});

test('sends the vendor credentials', ...);
// dataset: Anthropic -> https://api.anthropic.com/v1/models with headers x-api-key and anthropic-version: 2023-06-01;
// OpenAi -> https://api.openai.com/v1/models with Bearer; Mistral -> https://api.mistral.ai/v1/models with Bearer;
// each response {"data":[{"id":"m"}]} -> ['m']

test('returns an empty list without calling out when the credential is missing', ...);
test('returns null on an error status or connection failure', ...);
test('returns null on 200 with an unexpected JSON shape', ...);
// body ['foo' => 'bar'] and a non-JSON body

// AiProviderResourceTest.php
test('the model field suggests fetched models', ...);
// mount create, fill Ollama url with Http::fake -> assertSeeHtml('<option value="a:1"') or equivalent

test('editing with an empty key field fetches with the stored key', ...);
// Http::assertSent with the stored key

test('a failed fetch shows a hint and a free-text model still saves', ...);
// Http::fake 500; assertSee 'Modelle konnten nicht geladen werden'; callAction create succeeds
```

- [ ] **Step 2: Run tests to verify they fail.** Run `docker compose exec app php artisan test --compact tests/Feature/Ai/ProviderModelsTest.php`. Expected: FAIL, class not found.

- [ ] **Step 3: Implement `ProviderModels` and wire the form**

Use base URLs from `config("ai.providers.{vendor}.url")`, with `https://api.mistral.ai/v1` for Mistral, a 5 second timeout, and `limit=1000` for Anthropic. Catch connection and request failures.

Cache each outcome for 60 seconds, including failures, because the form re-renders often. Key the cache on `sha256` of vendor, url and key, so the plain key never becomes a cache key. Do not filter models.

On the `model` field use `TextInput::datalist()` with a closure and a `helperText` "Modelle konnten nicht geladen werden. Du kannst den Namen auch eintippen." shown when the result is null. The closures read `vendor`, `url` and `api_key` via `Get`; on edit with an empty key use the record's `readableKey()`.

- [ ] **Step 4: Run tests to verify they pass.** Run `docker compose exec app php artisan test --compact tests/Feature/Ai/ProviderModelsTest.php tests/Feature/Panel/AiProviderResourceTest.php`. Expected: PASS.

- [ ] **Step 5: Commit:** `git commit -m "Suggest models fetched from the provider"`.

---

### Task 5: Fixable failures on the import page

**Files:**
- Create: `app/Enums/ProviderFailureReason.php`, `app/Ai/ProviderFailure.php`, migration `add_failure_details_to_imports_table`
- Modify: `app/Jobs/ExtractImport.php`, `app/Models/Import.php`, `database/factories/ImportFactory.php`, `app/Filament/Resources/Imports/Schemas/ImportInfolist.php`, `app/Filament/Resources/Imports/Pages/ViewImport.php`
- Test: `tests/Feature/Ai/ProviderFailureTest.php`, `tests/Feature/Jobs/ExtractImportTest.php`, `tests/Feature/Panel/ViewImportTest.php`

**Interfaces:**
- Consumes: `AiProvider::scrub()`, `AiProviderResource::getUrl('index')`.
- Produces: `enum ProviderFailureReason: string` with `KeyRejected`, `KeyUnreadable`, `UnknownModel`, `ImagesUnsupported`, `Unreachable`; `static fromThrowable(Throwable $e): ?self`; `message(string $providerName): string`.
- Produces: `final class ProviderFailure extends RuntimeException` with `public readonly ?ProviderFailureReason $reason`, `public readonly string $providerName`; `static from(Throwable $e, AiProvider $provider): self`. Its message is `class: scrubbed message`, with no previous exception, because the previous message may echo the key.
- Produces: `Import` columns `failure_reason` (nullable, cast to the enum) and `failure_provider` (nullable string); `Import::failureMessage(): ?string`; factory state `providerFailure(ProviderFailureReason $reason, string $providerName)`.

Fixed copy of `message()`:
- `KeyRejected`: `{name} hat den API-Key abgelehnt.`
- `KeyUnreadable`: `Der gespeicherte API-Key von {name} ist nicht mehr lesbar.`
- `UnknownModel`: `{name} kennt das eingestellte Modell nicht.`
- `ImagesUnsupported`: `Das bei {name} eingestellte Modell kann keine Fotos verarbeiten.`
- `Unreachable`: `{name} ist nicht erreichbar.`

- [ ] **Step 1: Write the failing tests**

```php
// ProviderFailureTest.php: classification
dataset('failures', [
    '401' => [401, [], ProviderFailureReason::KeyRejected],
    '403' => [403, [], ProviderFailureReason::KeyRejected],
    '404' => [404, [], ProviderFailureReason::UnknownModel],
    'invalid model' => [400, ['error' => ['code' => 'invalid_model']], ProviderFailureReason::UnknownModel],
    'no vision' => [400, ['error' => ['message' => 'This model does not support image input']], ProviderFailureReason::ImagesUnsupported],
    'too large' => [400, ['error' => ['message' => 'image exceeds 5 MB']], null],
    'server error' => [500, [], null],
    'credits' => [402, [], null],
]);
// exercise through the real gateways: Http::fake(['*' => Http::response($body, $status)]) and
// InventoryExtractor::make(aiProvider: $provider)->extract([...]) for each vendor, then
// expect(ProviderFailureReason::fromThrowable($caught))->toBe($expected)

test('a connection failure and an overloaded provider are unreachable', ...);
// Http::fake that throws ConnectionException; 503 response
test('DecryptException is an unreadable key', ...);
test('other exceptions are unclassified', ...);

// ExtractImportTest.php
test('stores reason and provider name for a fixable failure', ...);
// Http::fake 401, active provider named 'Claude schnell'; handle() throws ProviderFailure; failed($e) ->
// status Failed, failure_reason KeyRejected, failure_provider 'Claude schnell'

test('a vendor error echoing the key does not leak it', function () {
    Exceptions::fake();
    Http::fake(['*' => Http::response(['error' => ['message' => 'bad key sk-secret-3fa9']], 401)]);
    $provider = AiProvider::factory()->active()->create(['api_key' => 'sk-secret-3fa9']);
    $job = new ExtractImport($import = Import::factory()->create());

    try { $job->handle(); } catch (Throwable $e) { $job->failed($e); }

    expect($import->fresh()->error)->not->toContain('sk-secret');
    Exceptions::assertReported(fn (Throwable $e) => ! str_contains($e->getMessage(), 'sk-secret') && $e->getPrevious() === null);
});

test('a failure does not change the active provider', ...);
test('without an active provider a failure stays unclassified', ...);
// .env fallback: failure_reason null, because nothing on the settings page can fix it
test('a success clears earlier failure details', ...);

// ViewImportTest.php
test('a fixable failure names the provider and links to the settings with debug off', function () {
    config(['app.debug' => false]);
    $import = Import::factory()->failed()->providerFailure(ProviderFailureReason::KeyRejected, 'Claude')->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Claude hat den API-Key abgelehnt.')
        ->assertSee('Provider prüfen')
        ->assertSeeHtml(AiProviderResource::getUrl('index'))
        ->assertDontSee('Connection refused')
        ->assertDontSee('Die Analyse ist fehlgeschlagen');
});

test('other failures keep the general message with debug off', ...);
test('debug mode also shows the raw error', ...);
test('the failure sentence survives the provider being renamed or deleted', ...);
// no AiProvider row at all; sentence uses failure_provider and link still rendered
test('retry clears the failure details and runs on the provider active now', ...);
// callAction('retry') -> failure_reason and failure_provider null; Queue::assertPushed(ExtractImport::class)
```

- [ ] **Step 2: Run tests to verify they fail.** Run `docker compose exec app php artisan test --compact tests/Feature/Ai/ProviderFailureTest.php tests/Feature/Jobs/ExtractImportTest.php tests/Feature/Panel/ViewImportTest.php`. Expected: new tests FAIL.

- [ ] **Step 3: Implement classification**

`fromThrowable`:
- `DecryptException` → `KeyUnreadable`
- `ProviderConnectionException`, `ProviderOverloadedException`, Laravel `ConnectionException` → `Unreachable`
- `RequestException`: 401 or 403 → `KeyRejected`; 404 → `UnknownModel`
- 400 or 422 with a lower-cased body containing `invalid_model`, `unknown model` or `model not found` → `UnknownModel`
- 400 or 422 with a body containing `vision`, or `image` together with `support` → `ImagesUnsupported`
- anything else → null, as the spec requires for failures that cannot be recognised reliably

- [ ] **Step 4: Implement the job, model, page and retry**

In `ExtractImport::handle()`'s `catch`: with an active provider, wrap with `ProviderFailure::from($e, $aiProvider)`, `report()` the wrapper and throw it; without one, keep today's behaviour exactly.

`failed()` stores `failure_reason` and `failure_provider` when `$exception` is a `ProviderFailure`, otherwise null. The success update clears both. The retry action's update clears both.

In `ImportInfolist`, add a `TextEntry` `failure_message` with state `failureMessage().' Provider prüfen'`, colour danger, `url(AiProviderResource::getUrl('index'))`, visible when status is failed and `failure_reason` is set. The existing `error` entry is visible when failed and (debug is on or `failure_reason` is null), with its state logic unchanged.

- [ ] **Step 5: Run tests to verify they pass.** Same command. Expected: PASS.

- [ ] **Step 6: Commit:** `git commit -m "Name fixable provider failures on the import page"`.

---

### Task 6: "Testen" on the card

**Files:**
- Create: `app/Ai/ProviderTester.php`, `resources/ai/test-image.png`
- Modify: `app/Filament/Resources/AiProviders/Tables/AiProvidersTable.php`
- Test: `tests/Feature/Ai/ProviderTesterTest.php`, `tests/Feature/Panel/AiProviderResourceTest.php`

**Interfaces:**
- Consumes: `InventoryExtractor::make(aiProvider:)`, `extract()`, `ProviderFailure::from()`, `ProviderFailureReason::message()`.
- Produces: `ProviderTester::run(AiProvider $provider): ?string`, null on success, otherwise a German reason. It uses a 120 second timeout: `public const TIMEOUT_SECONDS = 120`.

- [ ] **Step 1: Create the test image.** Run `docker compose exec app php -r '$i=imagecreatetruecolor(64,64);imagefill($i,0,0,imagecolorallocate($i,220,30,30));mkdir("resources/ai");imagepng($i,"resources/ai/test-image.png");'`. Expected: a small PNG exists on the host.

- [ ] **Step 2: Write the failing tests**

```php
// ProviderTesterTest.php
test('succeeds on a complete structured answer with one image and the test timeout', function () {
    InventoryExtractor::fake([['title' => 'Rotes Quadrat', 'description' => 'Rot.']]);
    $provider = AiProvider::factory()->create(['model' => 'gpt-x']);

    expect(app(ProviderTester::class)->run($provider))->toBeNull();

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 1
        && $prompt->timeout === ProviderTester::TIMEOUT_SECONDS && $prompt->model === 'gpt-x');
});

test('reports the reason for a classified failure', ...);
// Http::fake 401, provider named 'Claude' -> 'Claude hat den API-Key abgelehnt.'
test('reports a general reason for an incomplete answer or unknown failure', ...);
// non-null German text, no raw exception text, and the failure is reported scrubbed
test('an unreadable key reports the reason instead of throwing', ...);

// AiProviderResourceTest.php
test('testing an inactive provider uses it and leaves it inactive', ...);
// callAction(TestAction::make('test')->table($inactive)); assertNotified; provider stays inactive, nothing stored on the row
test('a failed test notifies with the reason', ...);
```

- [ ] **Step 3: Run tests to verify they fail.** Run `docker compose exec app php artisan test --compact tests/Feature/Ai/ProviderTesterTest.php`. Expected: FAIL, class not found.

- [ ] **Step 4: Implement**

`ProviderTester::run()` uses `Image::fromPath(resource_path('ai/test-image.png'), 'image/png')` and `extract([...], self::TIMEOUT_SECONDS)`. It catches `Throwable`, wraps with `ProviderFailure::from()`, `report()`s it, and returns `$failure->reason?->message($provider->name) ?? 'Der Test ist fehlgeschlagen. Bitte Modell und Zugangsdaten prüfen.'`.

Add record action `test` labelled "Testen", without a modal, so Filament's button spinner shows it is working. Success notification: "{name} hat das Testbild beantwortet." Failure notification: title "Test fehlgeschlagen" with the reason as body. PHP's `max_execution_time` is 0 in the container, so no extra limit is needed.

- [ ] **Step 5: Run tests to verify they pass.** Run `docker compose exec app php artisan test --compact tests/Feature/Ai/ProviderTesterTest.php tests/Feature/Panel/AiProviderResourceTest.php`. Expected: PASS.

- [ ] **Step 6: Commit:** `git commit -m "Add a provider test on the Eigene KI page"`.

---

### Task 7: Documentation and whole-suite verification

**Files:**
- Modify: `README.md`, `CLAUDE.md` (outside the Boost-managed block), `.env.example`

- [ ] **Step 1: Update the docs.**
  - `CLAUDE.md` "AI": the active `AiProvider` drives the extractor, and `INVENTORY_AI_*` is the fallback with no active provider.
  - `CLAUDE.md` architecture: add an entry for `AiProvider` and the page, the partial index, the `APP_KEY` dependency of stored keys, and `ProviderFailure`.
  - `CLAUDE.md`: note the open self-registration risk from the spec.
  - `README.md`: the same in user terms.
  - `.env.example`: comment above `INVENTORY_AI_PROVIDER` saying it applies only without an active provider on "Eigene KI".

- [ ] **Step 2: Run the whole suite.** Run `docker compose exec app php artisan test --compact`. Expected: all pass.

- [ ] **Step 3: Run the formatter and static analysis.** Run `docker compose exec app ./vendor/bin/pint --dirty --format agent` and `docker compose exec app ./vendor/bin/phpstan analyse`. Expected: no remaining errors.

- [ ] **Step 4: Check the page renders.** Run `docker compose exec app php artisan migrate` and confirm with `curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/eigene-ki` that it answers 302 to the login rather than 500. The Chrome extension cannot load localhost here; ask the user to look at the card layout and the highlighted active card.

- [ ] **Step 5: Commit:** `git commit -m "Document AI provider settings"`.
