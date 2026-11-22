# Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Administrators of a fresh installation see a five-step "Tory einrichten" checklist on the overview, and AI is configured only under "Eigene KI" (no `.env` fallback).

**Architecture:** First the `.env` fallback goes: extraction agents require an `AiProvider`, jobs fail fast with a new `ProviderFailureReason::NoProvider`, `EmbeddingTarget::current()` becomes nullable and every embedding path treats null as "keyword search only". Then `household_settings` gets two timestamps, a small `Onboarding` service computes the steps as DTOs, and an `OnboardingWidget` (Blade view, plain CSS, two Filament actions) renders them above the search.

**Tech Stack:** Laravel 13, Filament 5 (widgets with actions), Livewire, Pest on Postgres, plain CSS through Vite and the panel's `HEAD_END` hook.

**Spec:** `docs/superpowers/specs/2026-11-22-onboarding-design.md` (PRD: https://kb.balt.io/doc/onboarding-auf-der-ubersicht-ki-nur-uber-eigene-ki-Dvuo2Mrmvg). Ticket TOR-49, branch `feature/TOR-49-onboarding`.

## Global Constraints

- All commands run in Docker: `docker compose exec app …`, `docker compose exec vite npm …`.
- Translation keys are English text; every new key gets its German in `lang/de.json`. `TranslationsTest` and `EnglishInterfaceTest` must stay green.
- UI word for the recognition role is the existing one: German „Bilderkennung“, English "Image recognition" (the spec says "Objekterkennung"; the UI has always said „Bilderkennung“). Search role: „Suche“ / "search". The AI page is „Eigene KI“ / "AI providers".
- No AI call on any page load.
- Panel CSS is plain CSS (no Tailwind classes), added to both `vite.config.js` `input` and the `@vite([...])` list in `AdminPanelProvider` (`PanelsRenderHook::HEAD_END`), and works in light and dark mode (`.dark` ancestor, as Filament sets it).
- Constructor injection; DTOs over arrays; YAGNI. Write the failing test first.
- Before each commit: `./vendor/bin/rector process`, `./vendor/bin/pint --dirty`, `./vendor/bin/phpstan analyse` (the final task runs `composer test`).
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` and mention `(TOR-49)` in the subject.
- After a migration: `docker compose exec app php artisan migrate` on the dev database too.

## Review Focus

1. **A plain user calls the widget's actions directly** (Livewire request): `confirmContentLanguage` and `dismiss` must refuse for non-admins, not only be hidden. Test in Task 6.
2. **An English administrator** sees the whole checklist in English, including explanations and the no-provider messages. Test in Task 6 (and `EnglishInterfaceTest`).
3. **A failed import is retried after a provider was activated**: the retry must run the extractor and complete. Test in Task 1.
4. **The last item is deleted after the onboarding finished**: it must not come back. Test in Task 5.
5. **"Passt so" or "Ausblenden" pressed twice / by two admins**: idempotent, no error, first timestamp kept. Test in Task 4.

---

### Task 1: Extraction requires an active provider

**Files:**
- Modify: `app/Enums/ProviderFailureReason.php`, `app/Ai/ProviderFailure.php`
- Modify: `app/Ai/Agents/InventoryExtractor.php`, `app/Ai/Agents/PhotoBoundaryChecker.php`
- Modify: `app/Jobs/ExtractImport.php`, `app/Jobs/RegenerateTexts.php`, `app/Jobs/GroupBulkImport.php`
- Modify: `config/ai.php` (remove `inventory.provider`, `inventory.model`)
- Modify: `lang/de.json`
- Test: `tests/Feature/Jobs/ExtractImportTest.php`, `tests/Feature/Jobs/RegenerateTextsTest.php`, `tests/Feature/Jobs/GroupBulkImportTest.php`, `tests/Feature/Ai/InventoryExtractorTest.php`, `tests/Feature/Ai/PhotoBoundaryCheckerTest.php`, `tests/Feature/Panel/ViewImportTest.php`

**Interfaces:**
- Produces: `ProviderFailureReason::NoProvider` (value `'no_provider'`); `ProviderFailure::noRecognitionProvider(): self`; `InventoryExtractor::__construct(AiProvider $aiProvider, Language $language = Language::German)`; `PhotoBoundaryChecker::__construct(AiProvider $aiProvider)`.

- [ ] **Step 1: Write the failing tests**

In `ExtractImportTest.php`:

```php
test('without an active provider the import fails at once with a pointer to Eigene KI', function (): void {
    InventoryExtractor::fake()->preventStrayPrompts();
    $import = Import::factory()->create();

    expect(fn () => (new ExtractImport($import))->handle())->toThrow(ProviderFailure::class);
    (new ExtractImport($import))->failed(ProviderFailure::noRecognitionProvider());

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_reason)->toBe(ProviderFailureReason::NoProvider)
        ->and($import->failure_provider)->toBeNull()
        ->and($import->failureMessage())->toBe('Keine KI für die Bilderkennung eingerichtet. Richte sie unter „Eigene KI“ ein.');
});
```

(Use the import factory state and the message accessor the existing tests use; adapt names if they differ. `preventStrayPrompts` proves no AI call.)

In `RegenerateTextsTest.php`: same shape, asserting `regeneration_failure_reason === NoProvider` and old texts kept.

In `GroupBulkImportTest.php`:

```php
test('without an active provider grouping keeps the time cuts and names the reason', function (): void {
    PhotoBoundaryChecker::fake()->preventStrayPrompts();
    // bulk import in status Grouping with time cuts, as the existing tests build it
    (new GroupBulkImport($bulkImport))->handle();

    $bulkImport->refresh();
    expect($bulkImport->status)->toBe(BulkImportStatus::Ready)
        ->and($bulkImport->grouping_incomplete)->toBeTrue()
        ->and($bulkImport->failure_reason)->toBe(ProviderFailureReason::NoProvider);
});
```

In `ViewImportTest.php` (Review Focus 3): a failed import with reason `NoProvider`, then `AiProvider::factory()->active()->create()`, `InventoryExtractor::fake([...])`, run the `retry` action, run the queued `ExtractImport` (or `Queue::fake()` then handle the pushed job): import is `Completed`.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs tests/Feature/Panel/ViewImportTest.php`
Expected: the new tests FAIL (no `NoProvider` case; jobs call the `.env` fallback).

- [ ] **Step 3: Implement**

- `ProviderFailureReason::NoProvider = 'no_provider'`; `message()` returns `__('No AI for image recognition is set up. Set one up under “AI providers”.')` (ignores the provider name). German: „Keine KI für die Bilderkennung eingerichtet. Richte sie unter „Eigene KI“ ein.“
- `ProviderFailure::noRecognitionProvider(): self` — message `'No active AI provider for image recognition.'`, reason `NoProvider`, provider name `''`.
- `InventoryExtractor` / `PhotoBoundaryChecker`: the provider parameter becomes required and non-null; `provider()` returns `$this->aiProvider->toLaravelProvider()`, `model()` returns `$this->aiProvider->model`; the `config('ai.inventory.*')` and `'anthropic'` fallbacks go.
- `ExtractImport` / `RegenerateTexts`: inside the `try`, `$aiProvider = AiProvider::active() ?? throw ProviderFailure::noRecognitionProvider();` before reading photos. No `report()` for `NoProvider` (it is a setup state, not an error). In `failed()`, store `failure_provider` / `regeneration_failure_provider` as null when the reason is `NoProvider`.
- `GroupBulkImport::handle()`: with no active provider, call `$this->bulkImport->finishGrouping(true, ProviderFailure::noRecognitionProvider())` and return, without an AI call. `BulkImport::finishGrouping` stores the provider as null for `NoProvider` (same rule).
- Remove `provider` and `model` from `config/ai.php` `inventory`.
- Existing tests: job tests that fake the extractor get `beforeEach(fn () => AiProvider::factory()->active()->create())`; assertions on raw error messages may now see the scrubbed `ProviderFailure` message (CLAUDE.md, testing conventions). `InventoryExtractorTest` / `PhotoBoundaryCheckerTest` construct agents with `AiProvider::factory()->make()`; delete the tests that asserted the `.env`/`anthropic` fallback (they test removed behaviour; this is approved by the spec).

- [ ] **Step 4: Run the tests**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs tests/Feature/Ai tests/Feature/Panel tests/Feature/Console`
Expected: PASS.

- [ ] **Step 5: Commit** — `git commit -m "Require an active provider for image recognition (TOR-49)"`

### Task 2: Search without a search provider is keyword-only

**Files:**
- Modify: `app/Ai/EmbeddingTarget.php`, `app/Ai/ItemEmbedder.php`, `app/Ai/ItemSearch.php`, `app/Ai/EmbeddingRunner.php`, `app/Console/Commands/EmbedItems.php`, `app/Ai/Eval/ExtractionEval.php`
- Modify: `config/ai.php` (remove the whole `inventory` block), `app/Filament/Resources/AiProviders/Tables/AiProvidersTable.php:90`
- Create: `app/Ai/NoSearchProvider.php`
- Test: `tests/Feature/Ai/ItemSearchTest.php`, `tests/Feature/Ai/ItemEmbedderTest.php`, `tests/Feature/Jobs/EmbedItemTest.php` (or wherever `EmbedItem` is tested), `tests/Feature/Console/EmbedItemsCommandTest.php` (existing file for `items:embed`), `tests/Feature/Ai/ExtractionEvalTest.php`, `tests/Feature/Panel/AiProviderResourceTest.php`

**Interfaces:**
- Produces: `EmbeddingTarget::current(): ?EmbeddingTarget`; `EmbeddingTarget::__construct(AiProvider $aiProvider, string $model, float $minSimilarity)`; `NoSearchProvider extends RuntimeException` thrown by `ItemEmbedder::embed()` when there is no target.

- [ ] **Step 1: Write the failing tests**

- `ItemSearchTest`: `test('without a search provider search matches keywords only and calls no AI')` — items "Bohrmaschine" and "Akkuschrauber", no provider active for search, `Embeddings::fake()->preventStrayEmbeddings()`, search "Bohrmaschine" finds only the drill.
- `ItemEmbedderTest`: `test('without a search provider an item is not embedded')` — `embedAndStore($item)` returns false, `embedding` stays null, no embedding call.
- `EmbedItems` command test: `test('items:embed without a search provider says so and succeeds')` — `artisan('items:embed')->expectsOutputToContain('Keine KI für die Suche eingerichtet')->assertSuccessful()`.
- `EmbedItem` job: handled without a provider, no exception, no embedding call.
- `AiProviderResourceTest` (activate-for-search modal, current line ~503): with no provider active for search the switch notice shows `'keins'`-style text for the current model instead of `(.env)`; assert `->assertMountedActionModalDontSee('.env')`.
- Existing search/embedder tests that relied on the `.env` target create `AiProvider::factory()->embedding('bge-m3')->activeForSearch()->create()` first.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai tests/Feature/Console tests/Feature/Jobs`
Expected: the new tests FAIL.

- [ ] **Step 3: Implement**

- `EmbeddingTarget`: `current()` returns null without `AiProvider::activeForSearch()`; otherwise `new self($provider, (string) $provider->embedding_model, $provider->search_min_similarity ?? AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY)`. `forProvider()` uses `AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY`. `$envProvider`, the `env:` identity and the null branches go; `sdkProvider()` returns `$this->aiProvider->toLaravelProvider()->name()`.
- `ItemEmbedder::embed()`: `EmbeddingTarget::current() ?? throw new NoSearchProvider`. `embedFor()` always wraps in `ProviderFailure::from()`. `embedAndStore()`: no target → return false before any call; the identity re-check compares `EmbeddingTarget::current()?->identity()`.
- `ItemSearch::apply()`: no target → keyword condition only, no `report()`.
- `EmbeddingRunner::run()`: no target → return a result with nothing embedded, before paging. `EmbedItems`: in that case print `Keine KI für die Suche eingerichtet, nichts zu tun.` (the command's other output is German, untranslated, as today) and return success.
- `ExtractionEval`: `embed()` throwing `NoSearchProvider` lands in its existing failure path (null vectors → keyword-only hits); line 152 reads the threshold from the non-null target it already has.
- `AiProvidersTable` switch notice: current model is `AiProvider::activeForSearch()?->embedding_model ?? __('none')` (German „keins“).
- Remove the rest of `config('ai.inventory')`; grep `ai.inventory` in `app/`, `tests/`, `config/` must be empty afterwards.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec app php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Commit** — `git commit -m "Drop the .env fallback for search (TOR-49)"`

### Task 3: "Eigene KI" names roles without a provider; `.env` docs go

**Files:**
- Modify: `app/Filament/Resources/AiProviders/Pages/ManageAiProviders.php:119-127`, `lang/de.json`
- Modify: `.env.example` (remove `INVENTORY_*`, keep `OLLAMA_URL`), `README.md` (AI section lines ~80-97), `CLAUDE.md` (AI providers bullet: drop the `.env` fallback sentence and Ollama `.env` lines; say local dev creates an Ollama provider `gemma4:e4b-mlx` + embeddings `bge-m3` under "Eigene KI"; invariant "Nothing switches AI providers on its own": drop "or to `.env`")
- Modify: `docs/superpowers/specs/2026-11-06-ai-provider-settings-design.md`, `docs/superpowers/specs/2026-11-06-selectable-embedding-provider-design.md` (one line at the top: "The `.env` fallback was removed by TOR-49, see 2026-11-22-onboarding-design.md.")
- Test: `tests/Feature/Panel/AiProviderResourceTest.php` (current lines ~224, 228, 834-861)

- [ ] **Step 1: Rewrite the subheading tests** to expect, per case:
  - both missing: „Noch keine KI für die Bilderkennung und die Suche aktiv.“ / "No AI is active for image recognition and search yet."
  - recognition missing: „Noch keine KI für die Bilderkennung aktiv.“ / "No AI is active for image recognition yet."
  - search missing: „Noch keine KI für die Suche aktiv.“ / "No AI is active for search yet."
  - both active: no subheading; and `assertDontSee('.env')` in every case.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact tests/Feature/Panel/AiProviderResourceTest.php` — Expected: FAIL on the new texts.
- [ ] **Step 3: Implement** the three strings in `getSubheading()`; remove the three old keys from `lang/de.json`; update the docs listed above.
- [ ] **Step 4: Run** `docker compose exec app php artisan test --compact tests/Feature/Panel tests/Feature/TranslationsTest.php` (path of `TranslationsTest` as in the repo) — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Say on Eigene KI which roles have no provider (TOR-49)"`

### Task 4: Household settings remember confirmation and the end of onboarding

**Files:**
- Create: migration `add_onboarding_to_household_settings_table` (`php artisan make:migration … --no-interaction`)
- Modify: `app/Models/HouseholdSettings.php`, `app/Filament/Pages/HouseholdSettingsPage.php`
- Test: `tests/Feature/Panel/HouseholdSettingsPageTest.php`, `tests/Feature/Models/HouseholdSettingsTest.php` (create if no model test exists; follow the folder convention of existing model tests)

**Interfaces:**
- Produces: columns `content_language_confirmed_at`, `onboarding_finished_at` (nullable `timestampTz` or `timestamp`, as sibling migrations use); `HouseholdSettings::confirmContentLanguage(): void`; `HouseholdSettings::finishOnboarding(): void`; casts `datetime`; `@property Carbon|null` docs for both.

- [ ] **Step 1: Write the failing tests**
  - `test('saving the household page confirms the content language')` — `Livewire::test(HouseholdSettingsPage::class)->call('save')`; `content_language_confirmed_at` not null.
  - `test('the first administrator setup does not confirm the content language')` — `User::createFirstAdministrator(...)`; `content_language_confirmed_at` still null.
  - `test('confirming and finishing twice keep the first time')` (Review Focus 5) — call each method, `travel(1)->hour()`, call again; timestamps unchanged.
  - The migration's backfill gets no automated test (`RefreshDatabase` runs it on an empty database); Step 4 verifies it by hand on the dev database, which has items.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact tests/Feature/Panel/HouseholdSettingsPageTest.php tests/Feature/Models` — Expected: FAIL.
- [ ] **Step 3: Implement**
  - Migration `up()`: add both nullable columns; then, if `DB::table('items')->exists()`, set both to `now()` on the single row.
  - `confirmContentLanguage()` / `finishOnboarding()`: set the column only when null (`forceFill` + `save`), so a second call keeps the first time.
  - `changeContentLanguage()` stays unchanged (the setup must not confirm); `HouseholdSettingsPage::save()` calls `changeContentLanguage(...)` then `confirmContentLanguage()`.
- [ ] **Step 4: Run** the tests, then `docker compose exec app php artisan migrate` and check in `psql` that the dev row (which has items) has both timestamps.
- [ ] **Step 5: Commit** — `git commit -m "Remember content language confirmation and end of onboarding (TOR-49)"`

### Task 5: The onboarding steps

**Files:**
- Create: `app/Onboarding/Onboarding.php`, `app/Onboarding/OnboardingStep.php` (new top-level folder like `app/Photos/`, `app/BulkImports/`)
- Test: `tests/Feature/Onboarding/OnboardingTest.php`

**Interfaces:**
- Consumes: Task 4's columns and methods; `AiProvider::active()`, `AiProvider::activeForSearch()`.
- Produces:
  - `final readonly class OnboardingStep { public function __construct(public string $key, public string $title, public bool $done, public string $url, public ?string $result, public string $explanation, public string $actionLabel) {} }` — `$key` is one of `content-language`, `recognition`, `search`, `spot`, `item`.
  - `final readonly class Onboarding` with `steps(): list<OnboardingStep>` (in that order), `openStep(): ?OnboardingStep` (first not done), `doneCount(): int`, and `isShown(?User $user): bool`.

- [ ] **Step 1: Write the failing tests**
  - `test('a fresh household has all five steps open, the content language first')` — keys in order, all `done === false`, `openStep()->key === 'content-language'`.
  - `test('each step is done by its own condition')` — dataset: confirm language → `content-language`; `AiProvider::factory()->active()` → `recognition` (result = provider name); `AiProvider::factory()->embedding()->activeForSearch()` → `search` (result = provider name); `Spot::factory()` → `spot`; `Item::factory()` → `item`.
  - `test('an open import does not count as the first item')`.
  - `test('a step done out of order is ticked and the open step is the first undone one')` — only a spot exists → `spot` done, `openStep()->key === 'content-language'`.
  - `test('the content language step names the language')` — result `'Deutsch'` (the `Language` label).
  - `test('only administrators see the onboarding')` — `isShown($plainUser)` false, `isShown($admin)` true.
  - `test('once every step is done the onboarding is finished for good')` (Review Focus 4) — make all five done, `isShown($admin)` false and `onboarding_finished_at` set; delete all items; `isShown($admin)` still false.
  - `test('a finished onboarding is not shown')` — `finishOnboarding()`, `isShown($admin)` false.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact tests/Feature/Onboarding` — Expected: FAIL (classes missing).
- [ ] **Step 3: Implement.** URLs: `HouseholdSettingsPage::getUrl()`, `AiProviderResource::getUrl()` (both AI steps), `SpotResource::getUrl('create')`, `ImportResource::getUrl('create')`. `isShown()`: false unless `$user?->isAdmin()`; false when `onboarding_finished_at` is set; when all steps are done, call `finishOnboarding()` and return false. Titles, explanations and action labels are translated strings (German in `lang/de.json`); their content per the spec's "The steps" section: what the step is for in one or two sentences; the search step says Claude cannot do search and names OpenAI, Mistral or Ollama; the spot step tells Lagerort (area such as „Keller“) from Lagerplatz (place in it such as „Regal 3, Fach 2“) and mentions the printable label with QR code. Action labels: „Passt so“ (language), „Eigene KI öffnen“ (both AI steps), „Lagerplatz anlegen“, „Erfassen“.
- [ ] **Step 4: Run** the tests — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Compute the onboarding steps (TOR-49)"`

### Task 6: The "Tory einrichten" widget on the overview

**Files:**
- Create: `app/Filament/Widgets/OnboardingWidget.php`, `resources/views/filament/widgets/onboarding-widget.blade.php`, `resources/css/onboarding.css`
- Modify: `app/Filament/Pages/Dashboard.php` (widget first in `getWidgetsContentComponent()`), `app/Providers/Filament/AdminPanelProvider.php:90`, `vite.config.js`, `lang/de.json`
- Test: `tests/Feature/Panel/OnboardingWidgetTest.php`

**Interfaces:**
- Consumes: Task 5's `Onboarding` and `OnboardingStep`; Task 4's `confirmContentLanguage()` / `finishOnboarding()`.
- Produces: Livewire widget `OnboardingWidget` (implements `HasActions`, `HasSchemas`; uses `InteractsWithActions`, `InteractsWithSchemas`; `$isLazy = false`) with actions `confirmContentLanguageAction()` and `dismissAction()`; `canView()` = `(new Onboarding)->isShown(auth()->user())`. `Onboarding` has no dependencies, so constructing it directly is fine. Static `canView()` and `getViewData()` get no injection, and `resolve()`/`app()` are ruled out.

- [ ] **Step 1: Write the failing tests**
  - `test('an administrator of a fresh household sees Tory einrichten above the search')` — `get('/')` sees „Tory einrichten“, „0 von 5 erledigt“, and the HTML order puts it before `placeholder="Wo ist …?"` (`assertSeeInOrder`).
  - `test('a plain user does not see the onboarding')`.
  - `test('only the open step shows its explanation and button')` — confirm language; see the recognition explanation and „Eigene KI öffnen“; don't see the spot explanation.
  - `test('every step title links to its page')` — `assertSee` each URL from Task 5.
  - `test('Passt so confirms the content language')` — `Livewire::test(OnboardingWidget::class)->callAction('confirmContentLanguage')`; timestamp set; „1 von 5 erledigt“.
  - `test('Ausblenden asks first and hides the onboarding for every administrator')` — `callAction('dismiss')` (requires confirmation); `onboarding_finished_at` set; a second admin's `get('/')` does not see „Tory einrichten“.
  - `test('a plain user cannot run the onboarding actions')` (Review Focus 1) — as plain user, `Livewire::test(OnboardingWidget::class)` cannot mount or `assertActionHidden`/`callAction` changes nothing; timestamps stay null. Actions use `->authorize(fn (): bool => auth()->user()?->isAdmin() ?? false)` or `->visible(...)` plus a server-side check.
  - `test('an English administrator sees the onboarding in English')` (Review Focus 2) — English user + `Accept-Language: en`; sees "Set up Tory", "0 of 5 done", no German step title.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact tests/Feature/Panel/OnboardingWidgetTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement**
  - View inside `<x-filament-widgets::widget>` + `<x-filament::section>` with heading „Tory einrichten“ and the dismiss action in the section's header (`afterHeader` slot or Filament's section header actions).
  - Body as in the agreed mockup (spec, "Look"): progress text `trans_choice`-free „:done von :total erledigt“ / ":done of :total done"; a bar (`--tory-progress: N%`); an `<ol>` of steps, each with a status dot (✓ for done, the number otherwise) in one fixed-width column, the title as a link, a done step's result after „ · “; the open step's `<li>` gets a highlight class, its explanation and its button below the text (for `content-language`: the `confirmContentLanguage` action plus a „Ändern“ link to the household page; for the others: a primary `<x-filament::button tag="a">` to the step URL).
  - CSS in `resources/css/onboarding.css`, prefix `tory-onboarding`: highlight with a light primary tint via Filament's CSS variables (`--primary-50` / `--primary-500`, dark variants under `.dark`), the highlight must not shift the dot column (same padding on every row, background only on the open one), done dots `--success-500`, later dots neutral border.
  - Register the CSS in `vite.config.js` `input` and the `HEAD_END` `@vite([...])` list.
  - Dashboard: `...$this->getWidgetsSchemaComponents([OnboardingWidget::class])` first in the outer `Grid::make(1)`.
- [ ] **Step 4: Run** `docker compose exec app php artisan test --compact` — Expected: PASS.
- [ ] **Step 5: Browser check** with `playwright-cli` on http://localhost:8000 as an admin of a fresh-looking household (reset the two timestamps in dev with tinker or psql and restore them after): light and dark mode, phone width (375 px), states 0/5, 3/5 and the language step open. Compare with the agreed mockup.
- [ ] **Step 6: Commit** — `git commit -m "Show the onboarding on the overview (TOR-49)"`

### Task 7: Vocabulary, docs and full checks

**Files:**
- Modify: `CONTEXT.md` (new term under a fitting heading: **Onboarding** (Tory einrichten, English UI "Set up Tory"): the checklist an administrator sees on the overview until every step is done or it is dismissed; _Avoid_: Wizard, Setup (that is the first administrator setup)), `CLAUDE.md` (architecture map: one bullet "Onboarding (spec onboarding): `App\Onboarding\Onboarding`, `OnboardingWidget`, `household_settings.content_language_confirmed_at` / `onboarding_finished_at`")
- [ ] **Step 1:** Update both files.
- [ ] **Step 2:** Run `docker compose exec app composer test` — Expected: Rector, Pint, PHPStan, Psalm and the suite all pass.
- [ ] **Step 3:** Commit — `git commit -m "Document the onboarding (TOR-49)"`
- [ ] **Step 4:** Draft release-note bullets for the next tag (shown to the user, not tagged): new administrators get a setup checklist on the overview; AI is now set up only under „Eigene KI“ (no `.env` settings any more).
