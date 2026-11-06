# Selectable Embedding Provider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the embedding provider selectable on "Eigene KI", with a probe, a confirmation and a full recomputation on every switch.

**Architecture:** `EmbeddingTarget` answers "which provider, model and threshold apply now", read fresh on every call. `ItemEmbedder`, `ItemSearch`, the jobs and the eval all go through it. `SearchProviderSwitch` owns probing and the transactional switch; `EmbeddingRunner` owns the batch loop shared by `ReembedItems` and `items:embed`.

**Tech Stack:** Laravel 13, Filament 5, `laravel/ai`, Postgres 18 with pgvector, Pest.

**Spec:** `docs/superpowers/specs/2026-11-06-selectable-embedding-provider-design.md`

## Global Constraints

- Everything PHP runs in the container: `docker compose exec app ...`. Never host PHP.
- Dimension stays `ItemEmbedder::DIMENSIONS` (1024).
- Job timeouts stay below 210 s, which stays below `retry_after` 300 s.
- UI copy is German. Primary keys, DTOs and constructor injection follow `CLAUDE.md`; on Filament closures use typed parameters.
- Never assert a key in rendered output; no test reaches a real provider (`Embeddings::fake`, `Http::fake`).
- Generic embedding failure text, exact: `Das Embedding ist fehlgeschlagen. Bitte Modell und Zugangsdaten prüfen.`
- `Embeddings::generate()` takes a provider name, not an instance: pass `$aiProvider->toLaravelProvider()->name()`, which resolves the on-demand provider that `Ai::build()` just registered.
- Run Pint (`vendor/bin/pint --dirty --format agent`) and PHPStan before each commit.

## Review Focus

1. A running `EmbedItem` finishes after a switch: its old-model vector must not stay stored (Task 2).
2. An item that always fails must not make `ReembedItems` loop forever across its self-dispatches (Task 3).
3. A second switch while a recomputation runs: the running job must continue with the newest target (Task 3).
4. The form switches vendor to Claude after an embedding model was typed: nothing of it may be saved (Task 6).
5. A provider failure echoing the key must not reach `search_failure` or a notification (Task 3).

---

### Task 1: Schema and provider roles

**Files:**
- Create: `database/migrations/*_add_embedding_fields_to_ai_providers_table.php`
- Modify: `app/Models/AiProvider.php`, `app/Enums/AiVendor.php`, `database/factories/AiProviderFactory.php`
- Test: `tests/Feature/Ai/AiProviderSearchRoleTest.php`

**Interfaces:**
- Produces: columns `embedding_model` (string, nullable), `search_min_similarity` (float, nullable), `is_active_for_search` (boolean, default false), `search_failure` (text, nullable); `model` becomes nullable; partial unique index `ai_providers_one_search_active`.
- Produces: `AiProvider::activeForSearch(): ?self`; `AiVendor::supportsEmbeddings(): bool` (false only for `Anthropic`); factory states `embedding(string $model = 'text-embedding-3-small')` and `activeForSearch()`, the latter including an embedding model.

- [ ] **Step 1: Write the failing tests**

```php
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
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/AiProviderSearchRoleTest.php`
Expected: FAIL, unknown column or state.

- [ ] **Step 3: Add migration, casts, fillable, `activeForSearch()`, enum method and factory states**

`embedding_model` and `search_min_similarity` are fillable; `is_active_for_search` and `search_failure` are not. Cast `is_active_for_search` to boolean and `search_min_similarity` to float. Index: `CREATE UNIQUE INDEX ai_providers_one_search_active ON ai_providers (is_active_for_search) WHERE is_active_for_search`. Extend the `deleting` hook to refuse either active role.

- [ ] **Step 4: Run them and confirm they pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

`git add` the files above, then `git commit -m "Add embedding fields and a search role to providers"`.

### Task 2: Embedding target, embedder and search

**Files:**
- Create: `app/Ai/EmbeddingTarget.php`, `app/Ai/WrongEmbeddingDimensions.php`
- Modify: `app/Ai/ItemEmbedder.php`, `app/Ai/ItemSearch.php`, `app/Ai/ProviderFailure.php`, `app/Ai/Eval/ExtractionEval.php`
- Test: `tests/Feature/Ai/ItemEmbedderTest.php`, `tests/Feature/Ai/ItemSearchTest.php`, `tests/Feature/Ai/ProviderFailureTest.php`, `tests/Feature/Ai/ExtractionEvalTest.php`

**Interfaces:**
- Consumes: `AiProvider::activeForSearch()`, factory states from Task 1.
- Produces: `final readonly class EmbeddingTarget` with `public ?AiProvider $aiProvider`, `public string $model`, `public float $minSimilarity`; `static current(): self` (active provider, otherwise config; a null `search_min_similarity` falls back to config); `static forProvider(AiProvider $provider, string $model): self`; `identity(): string` (`provider:{id}:{model}` or `env:{provider}:{model}`); `sdkProvider(): string`. `sdkProvider()` decrypts the key, so only call it inside the embedder's `try`.
- Produces: `ItemEmbedder::embed(string $text, ?int $timeout = null, ?int $cacheSeconds = null): array` and `embedFor(EmbeddingTarget $target, string $text, ?int $timeout = null, ?int $cacheSeconds = null): array`. With an `aiProvider`, every `Throwable` leaves as `ProviderFailure::from($e, $provider)`; on the `.env` path it stays raw.
- Produces: `WrongEmbeddingDimensions extends RuntimeException`, constructor `(public readonly int $actual)`, keeping the existing message text.
- Produces: `ProviderFailure` gets a fourth constructor parameter `public readonly ?string $detail = null` and `userMessage(): ?string` returning `$detail ?? $reason?->message($providerName)`. For `WrongEmbeddingDimensions`, `from()` sets detail `Das Embedding-Modell von {name} liefert {actual} statt 1024 Dimensionen.`

- [ ] **Step 1: Write the failing tests**

In `ItemEmbedderTest.php`:

```php
test('the active search provider is used instead of the env configuration', function () {
    AiProvider::factory()->activeForSearch()->create(['vendor' => AiVendor::OpenAi, 'embedding_model' => 'text-embedding-3-small']);
    Embeddings::fake();

    app(ItemEmbedder::class)->embed('x');

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->model === 'text-embedding-3-small'
        && $prompt->provider->driver() === 'openai');
});

test('a failure on a provider leaves scrubbed', function () {
    AiProvider::factory()->activeForSearch()->create(['api_key' => 'sk-secret-9999']);
    Embeddings::fake(fn () => throw new RuntimeException('boom sk-secret-9999'));

    expect(fn () => app(ItemEmbedder::class)->embed('x'))
        ->toThrow(fn (ProviderFailure $e) => expect($e->getMessage())->not->toContain('sk-secret-9999'));
});

test('a vector of another length names both lengths', function () {
    AiProvider::factory()->activeForSearch()->create(['name' => 'OpenAI']);
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);

    expect(fn () => app(ItemEmbedder::class)->embed('x'))
        ->toThrow(fn (ProviderFailure $e) => expect($e->userMessage())
            ->toBe('Das Embedding-Modell von OpenAI liefert 768 statt 1024 Dimensionen.'));
});

test('a vector computed before a switch is not stored', function () {
    $item = Item::withoutEvents(fn () => Item::factory()->create());
    $next = AiProvider::factory()->embedding()->create();
    Embeddings::fake(function () use ($next) {
        $next->forceFill(['is_active_for_search' => true])->saveQuietly();

        return [array_fill(0, 1024, 0.1)];
    });

    expect(app(ItemEmbedder::class)->embedAndStore($item))->toBeFalse()
        ->and($item->fresh()->embedding)->toBeNull();
});
```

In `ItemSearchTest.php`:

```php
test('the threshold comes from the active search provider', function () {
    config(['ai.inventory.search_min_similarity' => 0.1]);
    AiProvider::factory()->activeForSearch()->create(['search_min_similarity' => 0.9]);
    $half = array_fill(0, 1024, 0.0);
    $half[1] = $half[2] = 1.0;
    queriesEmbedTo(axis(1));
    itemWith(['title' => 'Irgendwas'], $half);

    expect(searchItems('Suchbegriff')->count())->toBe(0);
});

test('a repeated query is embedded once', function () {
    $calls = 0;
    Embeddings::fake(function () use (&$calls) {
        $calls++;

        return [axis(1)];
    });

    app(ItemSearch::class)->apply(Item::query(), 'Kabel');
    app(ItemSearch::class)->apply(Item::query(), 'Kabel');

    expect($calls)->toBe(1);
});

test('a failed query embedding is not cached', function () {
    Exceptions::fake();
    Embeddings::fake(fn () => throw new RuntimeException('down'));
    searchItems('Kabel')->count();

    queriesEmbedTo(axis(1));
    $semantic = itemWith(['title' => 'Irgendwas'], axis(1));

    expect(searchItems('Kabel')->pluck('id')->all())->toBe([$semantic->id]);
});
```

In `ExtractionEvalTest.php`, add a case that asserts the active provider's threshold decides `WordHit`, mirroring the existing threshold test at line 59.

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/ItemEmbedderTest.php tests/Feature/Ai/ItemSearchTest.php tests/Feature/Ai/ExtractionEvalTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

- `embedFor()`: `Embeddings::for([$text])->dimensions(...)`, optional `timeout()`, optional `cache($cacheSeconds)`, then `generate($target->sdkProvider(), $target->model)`.
- `embedAndStore()`: take `EmbeddingTarget::current()` before the call. Inside the transaction, lock the item first, then return false when `EmbeddingTarget::current()->identity()` differs. The order matters: the switch's `UPDATE items` then waits for this lock and overwrites the row.
- `ItemSearch`: `$target = EmbeddingTarget::current()`, `embedFor($target, $term, timeout: 3, cacheSeconds: self::QUERY_CACHE_SECONDS)` with `QUERY_CACHE_SECONDS = 600`, and `1 - $target->minSimilarity`. Compute the target inside the existing `try`.
- `ExtractionEval.php:150`: use `EmbeddingTarget::current()->minSimilarity`.

- [ ] **Step 4: Run them and the existing suites and confirm they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai`
Expected: PASS, including all unchanged `.env` tests.

- [ ] **Step 5: Commit**

`git commit -m "Route embeddings through the provider active for search"`.

### Task 3: Batch runner and recomputation job

**Files:**
- Create: `app/Ai/EmbeddingRunner.php`, `app/Ai/EmbeddingRunResult.php`, `app/Jobs/ReembedItems.php`
- Modify: `app/Console/Commands/EmbedItems.php`
- Test: `tests/Feature/Jobs/ReembedItemsTest.php`, plus the existing `items:embed` test file (locate with `grep -rl "items:embed" tests`)

**Interfaces:**
- Consumes: `EmbeddingTarget`, `ItemEmbedder::embedAndStore()`, `ProviderFailure::userMessage()`.
- Produces: `EmbeddingRunner::run(Builder $items, ?string $afterId = null, ?int $budgetSeconds = null, int $pageSize = 500, ?Closure $onFailure = null): EmbeddingRunResult`.
- Produces: `final readonly class EmbeddingRunResult` with `int $embedded`, `int $failures`, `bool $stoppedEarly`, `?string $resumeAfterId` (set only when the budget ran out).
- Produces: `ReembedItems` with `__construct(public ?string $afterId = null)`, `$tries = 1`, `$timeout = 200`, `BUDGET_SECONDS = 150`. 150 s plus one 45 s client call stays under 200.
- `EmbedItems::MAX_CONSECUTIVE_FAILURES` stays, as an alias of `EmbeddingRunner::MAX_CONSECUTIVE_FAILURES` (3). `EmbedItems::$pageSize` stays.

- [ ] **Step 1: Write the failing tests** in `ReembedItemsTest.php`

Names and assertions:

- `it embeds every item without an embedding and leaves others alone`
- `it stops after three failures in a row and stores the reason`: active provider named "OpenAI", `Http::fake(['*' => Http::response([], 401)])` with a real on-demand call or a throwing fake producing `RequestException`; five items; assert `search_failure` contains `OpenAI hat den API-Key abgelehnt.` and the fake was called 3 times.
- `it does not store a key in the failure`: fake throws `RuntimeException('boom sk-secret-9999')`; assert `search_failure` is exactly the generic text from Global Constraints.
- `a run without failures clears the stored failure`
- `it dispatches itself with a cursor when the budget runs out`: fake that calls `$this->travel(151)->seconds()`; `Queue::fake()` must only apply after the handle call, so call `(new ReembedItems)->handle(...)` directly and assert `Queue::assertPushed(ReembedItems::class, fn ($job) => $job->afterId === $firstItem->id)`.
- `an item that always fails does not loop`: fake fails only for one item's text, and the budget is exhausted right after it; the follow-up job's `afterId` is past that item, and a second run with that cursor does not call the provider for it.
- `it continues with the newest target after a switch`: fake switches the active provider after the first item; the second item's recorded prompt uses the new model.
- `items:embed with an active provider stores a failure too`.

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs/ReembedItemsTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the runner, the job, and move the command onto the runner**

Runner rules: paging by `eachById`; with `$afterId`, add `where('id', '>', $afterId)`; check the budget with `now()` before each item; three consecutive failures stop the run. Note the provider at the start of the run. At the end, update that provider's `search_failure` only while it is still active for search (`AiProvider::query()->whereKey(...)->where('is_active_for_search', true)->update(...)`): last `ProviderFailure::userMessage()` or the generic text if the run had failures, otherwise null. On the `.env` path nothing is stored.

The job calls the runner with `Item::query()->whereNull('embedding')` and `BUDGET_SECONDS`, and dispatches `new self($result->resumeAfterId)` when that is set. The command keeps its output, passing its warning through `$onFailure`, and keeps `--all` by choosing the query.

- [ ] **Step 4: Run them and the command's tests and confirm they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs/ReembedItemsTest.php` plus the file found above.
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "Recompute embeddings in a self-continuing job"`.

### Task 4: Switching and probing

**Files:**
- Create: `app/Ai/SearchProviderSwitch.php`
- Test: `tests/Feature/Ai/SearchProviderSwitchTest.php`

**Interfaces:**
- Consumes: `ItemEmbedder::embedFor()`, `EmbeddingTarget::forProvider()`, `ReembedItems`.
- Produces: `SearchProviderSwitch::probe(AiProvider $provider, string $model): ?string` (null on success, otherwise a German reason; reports the `ProviderFailure`; `PROBE_TIMEOUT_SECONDS = 120`).
- Produces: `SearchProviderSwitch::switchTo(AiProvider $provider, string $model): void`.

- [ ] **Step 1: Write the failing tests**

```php
test('switching activates the provider, empties all embeddings and queues the job', function () {
    Queue::fake();
    $old = AiProvider::factory()->activeForSearch()->create();
    $new = AiProvider::factory()->embedding()->create();
    $item = Item::withoutEvents(fn () => Item::factory()->create(['embedding' => array_fill(0, 1024, 0.1)]));
    $updatedAt = $item->fresh()->updated_at;
    $this->travel(1)->minutes();

    app(SearchProviderSwitch::class)->switchTo($new, 'text-embedding-3-large');

    expect($old->fresh()->is_active_for_search)->toBeFalse()
        ->and($new->fresh()->is_active_for_search)->toBeTrue()
        ->and($new->fresh()->embedding_model)->toBe('text-embedding-3-large')
        ->and($item->fresh()->embedding)->toBeNull()
        ->and($item->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    Queue::assertPushed(ReembedItems::class);
});

test('switching clears a stored failure', function () {
    Queue::fake();
    $provider = AiProvider::factory()->embedding()->create();
    $provider->forceFill(['search_failure' => 'kaputt'])->saveQuietly();

    app(SearchProviderSwitch::class)->switchTo($provider, 'text-embedding-3-small');

    expect($provider->fresh()->search_failure)->toBeNull();
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
```

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/SearchProviderSwitchTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`switchTo()` in one transaction, like `AiProvider::activate()`: lock all provider rows, clear the old flag, set flag, model and `search_failure = null` on the new row, then `DB::table('items')->update(['embedding' => null])` (query builder, so no event fires and `updated_at` stays). Dispatch `ReembedItems` with `->afterCommit()`. Sync `$provider` in memory as `activate()` does.

- [ ] **Step 4: Run them and confirm they pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "Add probing and switching of the search provider"`.

### Task 5: "Testen" covers both roles

**Files:**
- Modify: `app/Ai/ProviderTester.php`, `app/Filament/Resources/AiProviders/Tables/AiProvidersTable.php` (success title only)
- Test: `tests/Feature/Ai/ProviderTesterTest.php`

**Interfaces:**
- Consumes: `SearchProviderSwitch::probe()`, injected through a new constructor on `ProviderTester`.
- `ProviderTester::run()` keeps its signature. Existing callers and single-role messages stay as they are.

- [ ] **Step 1: Write the failing tests**

- `only an embedding model runs only the probe`: no extractor prompt (`InventoryExtractor::assertNeverPrompted()`), result null.
- `only a model for object recognition does not embed`: `Embeddings::assertNothingGenerated()`, result null.
- `both configured and the embedding fails names both`: result is exactly `Bilderkennung: ok. Embedding: Das Embedding-Modell von OpenAI liefert 768 statt 1024 Dimensionen.`
- `both configured and the extraction fails names both`: result starts with `Bilderkennung: ` and ends with `Embedding: ok.`

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/ProviderTesterTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

Run each configured role. With one role, return its bare reason. With both and any failure, return `Bilderkennung: {reason|ok}. Embedding: {reason|ok}` with exactly one final period per part. The success title in the table reads `{name} hat den Test bestanden.` when an embedding model is set, otherwise the existing text.

- [ ] **Step 4: Run them and confirm they pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "Test the embedding model of a provider too"`.

### Task 6: Form, card and actions

**Files:**
- Modify: `app/Filament/Resources/AiProviders/Schemas/AiProviderForm.php`, `app/Filament/Resources/AiProviders/Tables/AiProvidersTable.php`, `app/Filament/Resources/AiProviders/Pages/ManageAiProviders.php`
- Test: `tests/Feature/Panel/AiProviderResourceTest.php`

**Interfaces:**
- Consumes: `SearchProviderSwitch::probe()`, `switchTo()`, `AiProvider::activeForSearch()`, `AiVendor::supportsEmbeddings()`.
- Produces: table action `activateForSearch`, form fields `embedding_model`, `search_min_similarity`, and the non-dehydrated `confirm_search_switch`.

Read the `filament-development` skill first.

- [ ] **Step 1: Write the failing tests**

```php
test('a failed probe keeps the dialog closed and changes nothing', function () {
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);
    $provider = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->mountAction(TestAction::make('activateForSearch')->table($provider))
        ->assertActionNotMounted()
        ->assertNotified();

    expect($provider->fresh()->is_active_for_search)->toBeFalse();
});

test('confirming the dialog switches and queues the recomputation', function () {
    Queue::fake();
    Embeddings::fake();
    $provider = AiProvider::factory()->embedding()->create();

    Livewire::test(ManageAiProviders::class)
        ->callAction(TestAction::make('activateForSearch')->table($provider));

    expect(AiProvider::activeForSearch()->is($provider))->toBeTrue();
    Queue::assertPushed(ReembedItems::class);
});
```

Further tests, by name:

- `the activation dialog names both models and the number of items`
- `activate for search is hidden without an embedding model and for the active one`
- `activate is hidden without a model for object recognition`
- `a provider needs at least one model`: both empty fails validation on `model` and `embedding_model`.
- `claude needs a model and offers no embedding fields`: `assertFormFieldHidden('embedding_model')`.
- `an embedding model typed before switching the vendor to claude is not saved`: fill vendor OpenAI and `embedding_model`, set vendor Anthropic, create; `embedding_model` is null.
- `the minimum similarity must lie between 0 and 1 and defaults to 0.5`
- `the role model cannot be emptied`: for each active role.
- `a changed embedding model on the active provider requires the checkbox`: without it, a validation error on `confirm_search_switch` and no change.
- `a confirmed model change probes and switches`: `Queue::assertPushed(ReembedItems::class)`, embeddings null, other fields saved.
- `a failed probe on edit saves nothing, not even other fields`: the name stays old, `assertNotified`.
- `editing the key, address or threshold does not recompute`: `Queue::assertNotPushed(ReembedItems::class)`, embeddings kept.
- `editing an inactive provider's embedding model does not recompute`
- `delete is disabled for the provider active for search`
- `the card shows progress and the stored failure`: `assertSee('Suche wird neu aufgebaut: 1 von 2')`, `assertSee` of the failure text.
- `the subheading names the env fallback per role`

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/AiProviderResourceTest.php`
Expected: new tests FAIL, existing ones PASS.

- [ ] **Step 3: Implement the form**

- `model`: label `Modell für Bilderkennung`, required when the vendor has no embeddings, or `embedding_model` is blank, or the record `is_active`. Empty string saves as null.
- `embedding_model`: label `Embedding-Modell`, `->visible(vendor supportsEmbeddings)`, required when `model` is blank or the record is active for search, same datalist as `model`, `->live(onBlur: true)`, trimmed, empty saves as null.
- `search_min_similarity`: label `Mindest-Ähnlichkeit`, numeric, 0 to 1, step 0.01, default 0.5, visible and required with a filled embedding model; helper text says values differ between models.
- `confirm_search_switch`: `Checkbox`, `->dehydrated(false)`, `->accepted()`, visible only when the record is active for search and the trimmed field differs from `$record->embedding_model`. Label states old and new model, the item count, incomplete search until the end and possible cost.

- [ ] **Step 4: Implement card and actions**

- Add columns: `embedding_model` with prefix `Embedding: `, prefix `Bilderkennung: ` on `model`, badge `Suche aktiv`, and `search_status` (text from counted items while any lack an embedding, otherwise null) plus `search_failure` with ` Es wird stündlich erneut versucht.` appended, coloured danger, both only on the provider active for search.
- `recordClasses`: highlight for either role.
- `->poll(fn (): ?string => ...)`: `5s` while a provider is active for search and items lack an embedding, else null.
- `activate`: also hidden when `model` is blank.
- `activateForSearch`: label `Für Suche aktivieren`, hidden without an embedding model or when active. `->requiresConfirmation()`, modal heading, description as in the spec. `->mountUsing(...)` runs the probe; on a reason it sends a danger notification and calls `$action->cancel()`, which `mountAction()` catches before any dialog opens. `->action(...)` calls `switchTo($record, $record->embedding_model)`.
- `EditAction`: add both fields to `fillForm`. In `->using(...)`, when the record is active for search and the model changed: `$record->fill($data)` in memory, probe, and on a reason `$record->refresh()`, notify and `$action->halt()`. On success save and call `switchTo()`. Otherwise a plain `$record->update($data)`.
- `DeleteAction`: disabled for either role, same tooltip.
- `ManageAiProviders::getSubheading()`: `Aktuell gilt die Serverkonfiguration (.env).` for no active provider at all; when only one role lacks a provider, name that role: `Für die Bilderkennung gilt die Serverkonfiguration (.env).` or `Für die Suche gilt die Serverkonfiguration (.env).`

- [ ] **Step 5: Run the file and confirm it passes**

Same command as Step 2. Expected: PASS. If `search_status` makes the existing "no card is marked active" or "subheading" tests fail, update only their expected text for the new role wording.

- [ ] **Step 6: Commit**

`git commit -m "Choose the search provider on the Eigene KI page"`.

### Task 7: Export and import

**Files:**
- Modify: `app/Ai/ProviderTransfer.php`, `app/Ai/ProviderTransferEntry.php`
- Test: `tests/Feature/Ai/ProviderTransferTest.php`

**Interfaces:**
- `ProviderTransferEntry` gets `?string $model`, `?string $embeddingModel = null`, `?float $searchMinSimilarity = null`; the file keys are `embedding_model` and `search_min_similarity`. `VERSION` stays 1.

- [ ] **Step 1: Write the failing tests**

- `export and import keep the embedding fields`: roundtrip; values equal.
- `neither active role is transferred`: exported from an active-for-search provider, imported fresh; `is_active_for_search` false and `Queue::assertNotPushed(ReembedItems::class)`.
- `an old file without the new fields still imports`: fields null.
- `an entry with only an embedding model imports`: `model` null.
- `an entry without any model fails the whole import`: throws `InvalidArgumentException`, no rows.
- `claude entries need a model and drop an embedding model`.
- `a threshold outside 0 to 1 fails the import`.

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/ProviderTransferTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

Rules: `model` becomes `required_without:providers.*.embedding_model`, `required_if:providers.*.vendor,anthropic`, `nullable`, string, max 255. `embedding_model`: `exclude_if` vendor anthropic, nullable, string, max 255. `search_min_similarity`: nullable, numeric, between 0 and 1. Update the array shapes in the docblocks. Trim values on import; an empty string becomes null.

- [ ] **Step 4: Run them and confirm they pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -m "Carry embedding settings in the provider transfer"`.

### Task 8: Documentation and final check

**Files:**
- Modify: `CLAUDE.md`, `CONTEXT.md`

- [ ] **Step 1: Update `CLAUDE.md`**

In "Embeddings", "Search", "AI providers" and "AI": the search role, `EmbeddingTarget`, `SearchProviderSwitch`, `ReembedItems`, `EmbeddingRunner`, the query cache, `.env` as fallback only. In "Queue timing invariant": `ReembedItems` with `$tries = 1`, `$timeout = 200`. Change the open risk to include switching. In "Testing conventions": embedding failures on a provider are wrapped in `ProviderFailure`.

- [ ] **Step 2: Update `CONTEXT.md`**

**Provider**: an AI service with its vendor, model(s) and credentials. It can serve object recognition, search or both. At most one is active for each role, and they may differ. Add a term **Embedding** only if the code's German copy needs it to stay unambiguous.

- [ ] **Step 3: Run the whole suite, Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact`, `docker compose exec app ./vendor/bin/pint --dirty --format agent`, `docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all pass, no PHPStan errors.

- [ ] **Step 4: Commit**

`git commit -m "Document the selectable embedding provider"`.
