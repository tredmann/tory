# Better extraction, search terms and eval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extracted texts name the thing instead of its packaging, carry AI-suggested search terms as removable tags, can be regenerated with one button, and are measured by an eval command against the user's own search words.

**Architecture:** `InventoryExtractor::extract()` returns an `ExtractedTexts` DTO. A `search_terms` jsonb column on `imports` and `items` feeds keyword search, the embedding text and a Filament `TagsInput`. A new `RegenerateTexts` job replaces texts on items and completed imports, keeping the old ones on failure. `extraction:eval` runs the real extractor on committed fixtures and scores in memory.

**Tech Stack:** Laravel 13, Filament 5, laravel/ai, Pest, Postgres 18 with pgvector, Ollama (`gemma4:e4b-mlx`, `bge-m3`).

**Spec:** `docs/superpowers/specs/2026-11-06-extraction-search-terms-design.md`

## Global Constraints

- Everything PHP runs in Docker: `docker compose exec app …`. Never use host PHP/Composer.
- UI copy is German. Label of the field: "Suchbegriffe". Button: "Neu generieren". Running state: "Wird neu generiert …".
- DTOs over arrays, except where a framework API demands arrays. Constructor injection; on Filament closures typed parameters.
- Failing test first. No automated test talks to a real model (`InventoryExtractor::fake`, `Embeddings::fake`; `TestCase::setUp()` already fakes embeddings).
- Never assert a literal id. Never assert or print an API key.
- Queue invariant: every job timeout < 210 < 300. `RegenerateTexts`: `$tries = 1`, `$timeout = 200`.
- The eval writes nothing to the database and dispatches no job. `--provider` is required; no fallback to the active provider (currently OpenAI).
- The real OpenAI run happens only after the user's explicit go in that moment.
- Never run `migrate:fresh` or anything else that wipes `tory`; the stored providers must survive. Normal `php artisan migrate` is fine.
- Before each commit: `docker compose exec app ./vendor/bin/pint --dirty --format agent`. Before finishing: `docker compose exec app ./vendor/bin/phpstan analyse`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

- A search for a character such as `[`, `,` or `"` must not match every item through the JSON text of `search_terms`. Task 2 matches array elements, not the JSON string.
- A model that returns `search_terms` as a string, with non-strings, blanks or case-variant duplicates, or not at all, must still complete the import with a clean list. Task 1.
- A rejected API key during an eval must not put the key in the terminal or the saved report. Task 5.
- A worker killed during regeneration must not leave the record on "Wird neu generiert …" forever, with its buttons hidden. Task 4 (`failed()`).
- Double-clicking "Neu generieren", or deleting the record while the job runs, must not start two paid runs or recreate the record or fail noisily. Task 4.

---

### Task 1: `search_terms` column and `ExtractedTexts` DTO

The prompt and the structured-output schema stay unchanged here, so the eval can later measure a baseline of the old prompt. `extract()` already parses terms if a model or fake supplies them.

**Files:**
- Create: `database/migrations/*_add_search_terms_to_imports_and_items.php`, `app/Ai/ExtractedTexts.php`
- Modify: `app/Models/Import.php`, `app/Models/Item.php`, `app/Ai/Agents/InventoryExtractor.php:72-90`, `app/Jobs/ExtractImport.php:50-81`, `app/Filament/Resources/Imports/Pages/ViewImport.php:34-41`
- Test: `tests/Feature/Ai/InventoryExtractorTest.php`, `tests/Feature/Jobs/ExtractImportTest.php`, `tests/Feature/Panel/ViewImportTest.php`

**Interfaces:**
- Produces: `final readonly class App\Ai\ExtractedTexts { __construct(public string $title, public string $description, public array $searchTerms) }` (`list<string>`); `InventoryExtractor::extract(array $attachments, ?int $timeout = null): ExtractedTexts`; `search_terms` attribute (array cast, fillable) on `Import` and `Item`.

- [ ] **Step 1: Create the branch and commit the spec**

```bash
git checkout -b extraction-search-terms
git add docs/superpowers/specs/2026-11-06-extraction-search-terms-design.md docs/superpowers/plans/2026-11-06-extraction-search-terms.md
git commit -m "Add design and plan for extraction, search terms and eval"
```

- [ ] **Step 2: Write the failing tests**

In `InventoryExtractorTest.php`:
- `extract returns a DTO with cleaned search terms`: fake `search_terms => [' Hefter ', 'hefter', 'Tacker', '', 42]`; expect `searchTerms` is `['Hefter', 'Tacker']`, title and description set.
- `extract tolerates missing or unusable search terms`: dataset of missing key, `'Hefter, Tacker'` string, `null`; expect `searchTerms === []` and no exception.

In `ExtractImportTest.php`:
- `stores the search terms`: after handling, `$import->fresh()->search_terms` is `['Hefter']`.
- `failed clears the search terms`: an import with terms, `failed()`, terms are `[]`.

In `ViewImportTest.php`:
- `retry clears the search terms`.

- [ ] **Step 3: Run the tests, expect failures**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/InventoryExtractorTest.php tests/Feature/Jobs/ExtractImportTest.php tests/Feature/Panel/ViewImportTest.php`
Expected: new tests FAIL.

- [ ] **Step 4: Implement**

- Migration (`php artisan make:migration --no-interaction`): `$table->jsonb('search_terms')->default('[]');` on both tables, dropped in `down()`.
- Add to `$fillable`, and `'search_terms' => 'array'` to `casts()` with its PHPDoc shape, on both models.
- `extract()`: keep the title and description check, build the DTO. Cleaning: non-array becomes `[]`, keep strings only, trim, drop empty, unique case-insensitively with `mb_strtolower`, keep first spelling, `array_values`.
- `ExtractImport`: store `search_terms`; `failed()` and the retry action set `search_terms` to `[]`.
- `ProviderTester` ignores the return value and needs no change.

- [ ] **Step 5: Run migration and tests**

Run: `docker compose exec app php artisan migrate --no-interaction && docker compose exec app php artisan test --compact tests/Feature/Ai tests/Feature/Jobs tests/Feature/Panel/ViewImportTest.php`
Expected: PASS.

- [ ] **Step 6: Commit** — "Store search terms and return extracted texts as a DTO"

### Task 2: Search and embedding use the terms

**Files:**
- Modify: `app/Ai/ItemSearch.php:72`, `app/Ai/ItemEmbedder.php:67-72`, `app/Models/Item.php:40-50`, `database/factories/ItemFactory.php` only if a test needs a state
- Test: `tests/Feature/Ai/ItemSearchTest.php`, `tests/Feature/Ai/ItemEmbedderTest.php`, `tests/Feature/Models/` (the file holding the item event tests; check with `grep -rl EmbedItem tests/Feature/Models`, else `tests/Feature/Jobs/EmbedItemTest.php`)

**Interfaces:**
- Consumes: `search_terms` on `Item` (Task 1).
- Produces: `ItemEmbedder::textFor(Item $item): string` includes terms; used unchanged by the eval.

- [ ] **Step 1: Write the failing tests**

`ItemSearchTest`:
- `finds an item through a search term`: item with `search_terms => ['Tacker']`, title and description without it; term `tacker` finds it.
- `every word may match in a different field`: title "Hefter", term "Tacker"; query `hefter tacker` finds it.
- `finds umlauts in search terms`: terms `['Heftgerät']`, query `heftgerät` and `HEFTGERÄT` find it.
- `json syntax does not match search terms`: dataset `[`, `]`, `,`, `"`; an item with terms `['A', 'B']`, and with no such character in title or description, is not found by keyword (make the embedding fail with `Embeddings::fake(fn () => throw new RuntimeException)` and `Exceptions::fake()` so only keyword applies).

`ItemEmbedderTest`:
- `text for includes search terms`: `textFor` of title `T`, description `D`, terms `['A', 'B']` is `"T\n\nD\n\nSuchbegriffe: A, B"`.
- `text for without search terms equals the previous text`: terms `[]` gives `"T\n\nD"`, and with empty description `"T"`.

Item events:
- `changing search terms clears the embedding and dispatches EmbedItem`.
- `saving unchanged search terms dispatches nothing`.

- [ ] **Step 2: Run them, expect failures**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/ItemSearchTest.php tests/Feature/Ai/ItemEmbedderTest.php`
Expected: new tests FAIL.

- [ ] **Step 3: Implement**

- `keywordCondition()` per-word fragment, with three bound patterns per word:

```php
"(title ilike ? or coalesce(description, '') ilike ? or exists (select 1 from jsonb_array_elements_text(search_terms) as term where term ilike ?))"
```

- `textFor()`: append `"\n\nSuchbegriffe: ".implode(', ', $terms)` only when terms exist. The result must also work with an empty description: `"T\n\nSuchbegriffe: A"`.
- `Item` events: `['title', 'description', 'search_terms']` in both `isDirty` and `wasChanged`. Update the `EmbedItem` class comment.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai tests/Feature/Jobs tests/Feature/Models`
Expected: PASS.

- [ ] **Step 5: Commit** — "Search and embed items through their search terms"

### Task 3: Tags in the panel

**Files:**
- Modify: `app/Models/Import.php:93-107`, `app/Filament/Resources/Imports/Pages/ViewImport.php:53-71`, `app/Filament/Resources/Imports/Schemas/ImportInfolist.php`, `app/Filament/Resources/Items/Schemas/ItemInfolist.php`, `app/Filament/Resources/Items/Schemas/ItemForm.php`
- Test: `tests/Feature/Models/ImportTest.php`, `tests/Feature/Panel/ViewImportTest.php`, `tests/Feature/Panel/ItemResourceTest.php`

**Interfaces:**
- Produces: `Import::convertToItem(string $title, ?string $description, int $storageLocationId, array $searchTerms = []): Item`.

- [ ] **Step 1: Write the failing tests**

- `ImportTest`: `conversion takes over the search terms`.
- `ViewImportTest`: `convert prefills the search terms` with `assertSchemaStateSet`-style check as existing tests do for the convert form; `convert stores the edited search terms`: call with `['search_terms' => ['Tacker']]` where the import had `['Tacker', 'Büro']`, item has `['Tacker']`; `the view page shows search terms`.
- `ItemResourceTest`: `the view page shows search terms`; `the edit page removes a search term`: `fillForm(['search_terms' => ['A']])->call('save')`, item has `['A']`.

- [ ] **Step 2: Run them, expect failures**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/ImportTest.php tests/Feature/Panel/ViewImportTest.php tests/Feature/Panel/ItemResourceTest.php`
Expected: new tests FAIL.

- [ ] **Step 3: Implement**

Check `TagsInput` with `search-docs` first (activate the `filament-development` skill).
- `TagsInput::make('search_terms')->label('Suchbegriffe')->splitKeys(['Tab', ','])` in the convert form and `ItemForm`, after the description.
- Convert: `fillForm` adds `search_terms`; action passes `$data['search_terms'] ?? []`.
- Infolists: `TextEntry::make('search_terms')->label('Suchbegriffe')->badge()`, after the description. Item: `->placeholder('—')`. Import: visible when filled, like title.

- [ ] **Step 4: Run the tests**

Same command as Step 2. Expected: PASS.

- [ ] **Step 5: Commit** — "Show and edit search terms as tags"

### Task 4: "Neu generieren"

**Files:**
- Create: migration `*_add_regeneration_state_to_imports_and_items.php`, `app/Models/Concerns/RegeneratesTexts.php`, `app/Ai/PhotoAttachments.php`, `app/Jobs/RegenerateTexts.php`, `app/Filament/Actions/RegenerateTextsAction.php`, `app/Filament/Schemas/RegenerationEntries.php`
- Modify: `app/Models/Import.php`, `app/Models/Item.php`, `app/Jobs/ExtractImport.php:40-46`, `app/Filament/Resources/Imports/Pages/ViewImport.php`, `app/Filament/Resources/Imports/Schemas/ImportInfolist.php`, `app/Filament/Resources/Items/Pages/ViewItem.php`, `app/Filament/Resources/Items/Pages/EditItem.php`, `app/Filament/Resources/Items/Schemas/ItemInfolist.php`
- Test: `tests/Feature/Jobs/RegenerateTextsTest.php`, `tests/Feature/Panel/RegenerateTextsActionTest.php`

**Interfaces:**
- Consumes: `ExtractedTexts`, `extract()` (Task 1).
- Produces:
  - Columns on both tables: `regenerating_at` (nullable timestamp), `regeneration_error` (nullable text), `regeneration_failure_reason` (nullable string, cast `ProviderFailureReason`), `regeneration_failure_provider` (nullable string).
  - Trait `RegeneratesTexts`: `isRegenerating(): bool`; `startRegeneration(): bool` (atomic: query-builder update `where regenerating_at is null`, true when one row changed, clears the error fields, no model events); `regenerationFailureMessage(): ?string`.
  - `PhotoAttachments::fromStorage(array $paths): array` (`list<Image>`), the loop moved from `ExtractImport`, same "wurde nicht gefunden" exception.
  - `RegenerateTexts(public Import|Item $record)`, `$tries = 1`, `$timeout = 200`, `$deleteWhenMissingModels = true`.

- [ ] **Step 1: Write the failing job tests**

`RegenerateTextsTest`, for item and for completed import where it makes sense (dataset):
- `replaces title, description and terms and clears the running state`.
- `a changed item text dispatches EmbedItem` with `Queue::fake()`.
- `keeps the old texts when the extractor fails`: `InventoryExtractor::fake(fn () => throw …)`, run through `handle()` then `failed($e)`; texts and terms unchanged, `regenerating_at` null, `regeneration_error` set.
- `keeps the old texts when a photo is missing`.
- `stores the failure reason and provider for an active provider`: rejected key on `AiProvider::factory()->active()`, `regeneration_failure_reason` and `regeneration_failure_provider` set, import `status` stays `Completed`.
- `failed without an exception clears the running state`: simulates the queue giving up after a hard kill; `failed(null)`, `regenerating_at` null, error is 'Der Auftrag wurde abgebrochen.'.
- `a record deleted during the run ends quietly`: fake closure deletes the record; no exception, `Item::count() === 0`, no row recreated.
- `failed on a deleted record does nothing`.

- [ ] **Step 2: Write the failing panel tests**

`RegenerateTextsActionTest`:
- `the action starts the job on an item` and `on a completed import`: `callAction('regenerate')`, `Queue::assertPushed(RegenerateTexts::class)`, `isRegenerating()`.
- `the action is hidden on pending, running and failed imports`.
- `a second start dispatches no second job`: `startRegeneration()` called twice returns true then false.
- `while regenerating, regenerate, edit and convert are hidden`: `assertActionHidden` for `regenerate`, `edit` on `ViewItem` and `convert` on `ViewImport`.
- `while regenerating, the page shows the state and polls`: `assertSee('Wird neu generiert …')`, `assertSeeHtml('wire:poll.5s')`.
- `the edit page redirects to the view page while regenerating`.
- `a failure shows the reason with the provider link`, and `an unclassified failure shows the general sentence`, with `app.debug` off.
- `the action is hidden without photos`.

- [ ] **Step 3: Run them, expect failures**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs/RegenerateTextsTest.php tests/Feature/Panel/RegenerateTextsActionTest.php`
Expected: FAIL, classes missing.

- [ ] **Step 4: Implement the state and the job**

- `handle()`: load photos with `PhotoAttachments`, take `AiProvider::active()`, `extract()`. On exception wrap, report and rethrow exactly as `ExtractImport::handle()`.
- On success, in a transaction: reload the record with `lockForUpdate()`. Null means it was deleted or converted: return. Otherwise a normal `update()` of `title`, `description`, `search_terms`, with `regenerating_at` and all error fields null, so item events re-embed.
- `failed(?Throwable $exception)`: query-builder update by key (no model events, cannot recreate a row, keeps the texts): `regenerating_at` null, `regeneration_error` from `$exception?->getMessage() ?: 'Der Auftrag wurde abgebrochen.'`, reason and provider as `ExtractImport::failed()` does.
- `ExtractImport` uses `PhotoAttachments`; its behaviour does not change.

- [ ] **Step 5: Implement the panel**

- `RegenerateTextsAction::make()`: name `regenerate`, label "Neu generieren", `requiresConfirmation()`, heading "Texte neu generieren?", description "Titel, Beschreibung und Suchbegriffe werden durch neue Vorschläge ersetzt, auch manuelle Änderungen. Das lässt sich nicht rückgängig machen. Mit einem kostenpflichtigen Provider kostet jeder Lauf Geld." Visible when photos exist, not regenerating, and for an import `status === Completed`. Action: `if ($record->startRegeneration()) { RegenerateTexts::dispatch($record); }` plus a success notification "Neu generieren gestartet".
- Add it to `ViewItem` and `ViewImport`. Hide `EditAction` and `convert` while `isRegenerating()`. `DeleteAction` stays.
- `EditItem::mount()`: while regenerating, redirect to the view page.
- `RegenerationEntries::make(): array`:
  - Running entry, state "Wird neu generiert …", visible while regenerating.
  - Reason entry: `regenerationFailureMessage().' Provider prüfen'`, danger, link to `AiProviderResource::getUrl('index')`.
  - Error entry with the rule of `ImportInfolist`: raw only with debug on, otherwise "Neu generieren ist fehlgeschlagen. Die bisherigen Texte sind erhalten." when no reason exists.
- Use the entries in both infolists. Poll with `wire:poll.5s` on the section: for an import when `status->isActive() || isRegenerating()`, for an item when `isRegenerating()`.

- [ ] **Step 6: Run the whole suite**

Run: `docker compose exec app php artisan migrate --no-interaction && docker compose exec app php artisan test --compact`
Expected: PASS.

- [ ] **Step 7: Commit** — "Regenerate texts and search terms with one button"

### Task 5: The eval

**Files:**
- Create: `tests/Evals/extraction/cases.json`, photos in `tests/Evals/extraction/photos/`, `app/Ai/Eval/EvalCase.php`, `app/Ai/Eval/WordHit.php`, `app/Ai/Eval/CaseResult.php`, `app/Ai/Eval/EvalRun.php`, `app/Ai/Eval/ExtractionEval.php`, `app/Ai/Eval/EvalReport.php`, `app/Console/Commands/EvalExtraction.php`
- Test: `tests/Feature/Ai/ExtractionEvalTest.php`, `tests/Feature/Console/EvalExtractionCommandTest.php`

**Interfaces:**
- Consumes: `ExtractedTexts`, `ItemEmbedder::embed()`, `ItemEmbedder::textFor()` on an unsaved `new Item([...])`, `ProviderFailure::from()`.
- Produces:
  - `EvalCase(string $name, list<string> $photos, list<string> $searchWords, list<string> $titleWords, list<string> $forbiddenWords)`; `EvalCase::loadAll(string $directory): list<EvalCase>` throws `RuntimeException` on an unreadable file or a missing photo; photos become absolute paths.
  - `WordHit(string $word, bool $keyword, bool $semantic, float $similarity)` with `found(): bool`.
  - `CaseResult(EvalCase $case, ?ExtractedTexts $texts, ?string $error, list<WordHit> $hits, list<string> $missingTitleWords, list<string> $forbiddenFound, list<string> $falseHits)`; a false hit reads `"hefter → RØDE Wireless GO"`.
  - `EvalRun(list<CaseResult> $results)`.
  - `ExtractionEval::run(AiProvider $provider, list<EvalCase> $cases): EvalRun`.
  - `EvalReport::render(string $providerName, list<EvalRun> $runs): string`.
- Signature: `extraction:eval {--provider= : Name des gespeicherten KI-Providers} {--runs=1 : Anzahl der Durchläufe}`.

- [ ] **Step 1: Copy the fixtures**

From `storage/app/private/imports/` to `tests/Evals/extraction/photos/` (copy, keep the originals):

| New name | Source |
|---|---|
| `rode-1.jpg` | `RYZy2QSIbprE7u3YOx4qsqNQKION55TQO3ariEwA.jpg` |
| `rode-2.jpg` | `PNbSeGAi3QRQkD57K5qL4PFsPiUbnVa0RaRIHgbb.jpg` |
| `rode-3.jpg` | `FtqcbTMpRh6CxCyQ7p1ntk9FnwBvLJee5ccLbiTe.jpg` |
| `apc.jpg` | `oy0uq1oL8tSO6k0UtOKAqGZLCyANMVpH28iF0JdS.jpg` |
| `raspberry-pi.jpg` | `UTmeU68kLvUF29ppUM4IhRsOK5GgtYz2DGmd6iGK.jpg` |
| `pelikan.jpg` | `fD80k6kYlD5DZXjmZ8PXm62G9DkRQl5fGzp2HMjV.jpg` |
| `soennecken-1.jpg` | `rZNXVbbqaqbAlJwuLFkDK89Jygw4OPUK3z81SAe3.jpg` |
| `soennecken-2.jpg` | `ItYLDZmCdJxV80gHCIgKYlRrbMkibloA7Ch03QiT.jpg` |

- [ ] **Step 2: Write `cases.json`**

An array of objects with `name`, `photos`, `search_words`, `title_words`, `forbidden_words`:

| name | search_words | title_words | forbidden_words |
|---|---|---|---|
| RØDE Wireless GO | mikrofon, rode | Mikrofon | Verpackung, Karton, Gebrauchsspuren, beschädigt |
| APC | apc, usv, strom, steckdose | USV | Netzteil, Gehäuse |
| Raspberry Pi 5 | raspberry, pi5 | Raspberry Pi 5 | Platine |
| Pelikan 4001 | patronen, tinte | Tintenpatronen | Verpackung, Packung, Best Quality |
| Soennecken 3155 | hefter, tacker, klammer | Hefter | Metallklammer, Gebrauchsspuren |

- [ ] **Step 3: Write the failing tests**

`ExtractionEvalTest` (call `ExtractionEval::run` with in-memory cases over `resources/ai/test-image.png`; drive similarity with `Embeddings::fake` closures and the `axis()` helper):
- `keyword hit is a case-insensitive substring in title, description or terms`: `klammer` hits "Heftklammern"; `rode` hits term `Rode`; `rode` does not hit "RØDE" alone.
- `semantic hit uses the configured threshold`: similarity at 0.5 hits, below does not, with `ai.inventory.search_min_similarity` 0.5.
- `a missing title word is reported`.
- `forbidden words match whole words in title and description only`: `Platine` not hit by "Einplatinencomputer"; hit by "Grüne Platine"; `Best Quality` hit as a phrase, case-insensitive; `Verpackung` as a search term is not hit.
- `reports a false hit on another case`.
- `a failing case is reported and the others still run`.
- `a rejected key never reaches the result`: active-less `AiProvider::factory()` with a known key, fake throwing a message containing that key; `CaseResult::$error` does not contain it.
- `writes nothing`: after a run, `Item::count()` and `Import::count()` are 0, `Queue::assertNothingPushed()`.
- `uses the given provider, not the active one`: an active provider and another; `InventoryExtractor::assertPrompted` with `$prompt->provider`/`model` as `ai-sdk-development` documents, or spy via the given provider's model.

`EvalExtractionCommandTest`:
- `the provider option is required`: `artisan('extraction:eval')` exits non-zero, and `InventoryExtractor::assertNeverPrompted()`.
- `an unknown provider lists the available names`.
- `runs must be at least 1`: dataset `0`, `abc`.
- `the committed cases load`: `EvalCase::loadAll(base_path('tests/Evals/extraction'))` has 5 cases and all photos exist.
- `a report is saved and printed`: `Storage::fake('local')`, one file under `evals/`, output contains the case names and the generated text.
- `exits non-zero when the embedding provider is down`: throwing `Embeddings::fake`, `assertNeverPrompted`.
- `exits non-zero when every case failed`, `exits zero when one case failed`, `exits zero with a low score`.
- `totals show the range across runs`: `--runs=2`, fake results differ, output contains `3–5`-style range.

- [ ] **Step 4: Run them, expect failures**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/ExtractionEvalTest.php tests/Feature/Console/EvalExtractionCommandTest.php`
Expected: FAIL, classes missing.

- [ ] **Step 5: Implement**

- Run order: extract all cases, then embed each result text (`textFor` on an unsaved `Item`) and each search word once, then score every word against every result. Cosine in PHP; `ItemEmbedder::embed` vectors are not guaranteed normalised, so divide by norms.
- Keyword: `mb_stripos` over title, description and each term separately.
- Forbidden: `/(?<![\p{L}\p{N}])WORD(?![\p{L}\p{N}])/iu` with `preg_quote`, on title and description joined by a newline.
- Extraction failure: `ProviderFailure::from($e, $provider)->getMessage()` into `$error`. Same scrubbing path as the jobs.
- Attachments: `Image::fromPath($path, 'image/jpeg')`; `InventoryExtractor::make(aiProvider: $provider)->extract($attachments)`.
- Command: validate options before anything else. Probe `$embedder->embed('test')` once, failing with a German message when it throws. `Storage::disk('local')->put('evals/'.now()->format('Y-m-d_His').'-'.Str::slug($provider->name).'.txt', $report)`, also print.
- Report: per run, a table of cases with found words (`Stichwort`/`Bedeutung` apart), title words, forbidden, false hits; then full title, description and terms per case. Totals at the end, with min–max when `--runs` > 1.

- [ ] **Step 6: Run the tests**

Same command as Step 4. Expected: PASS.

- [ ] **Step 7: Commit** — "Add an eval for the extraction"

- [ ] **Step 8: Measure the baseline on Gemma**

Run: `docker compose exec app php artisan extraction:eval --provider="Ollama lokal"` (takes minutes; use the Bash `run_in_background` option)
Expected: a report with low scores and empty terms, saved under `storage/app/private/evals/`. Keep the path for comparison. If the run cannot reach Ollama, stop and report.

### Task 6: New prompt, tuning and docs

**Files:**
- Modify: `app/Ai/Agents/InventoryExtractor.php:23-48`, `CLAUDE.md`
- Test: `tests/Feature/Ai/InventoryExtractorTest.php`

**Interfaces:**
- Consumes: eval command (Task 5).

- [ ] **Step 1: Write the failing tests**

- Replace `instructions are German and forbid inventing details` with: instructions contain `Deutsch`, `Erfinde`, `Suchbegriffe`/`search_terms`, and do not contain `Beschädigungen` as a request (`not->toContain('sichtbaren Zustand')`).
- `the schema requires search terms`: `schema()` has the key `search_terms` beside `title` and `description`.

- [ ] **Step 2: Run them, expect failures**

Run: `docker compose exec app php artisan test --compact tests/Feature/Ai/InventoryExtractorTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the first prompt**

Add `'search_terms' => $schema->array()->items($schema->string())->required()->description('Deutsche Suchbegriffe, die ein Mensch eintippen würde.')`. Keep the last JSON paragraph of the instructions word for word, and extend its object with `search_terms`. First draft of the rest, to tune in Step 5:

```text
Du hilfst bei der Inventarisierung. Die Fotos zeigen einen Gegenstand, den jemand später in seinem Inventar wiederfinden will. Beschreibe den Gegenstand selbst, nicht das Foto.

Erkenne, was der Gegenstand ist, und benenne das, auch wenn es nirgends aufgedruckt ist: Ein Bürohefter heißt Hefter, auch wenn nur eine Modellnummer zu lesen ist. Marke, Modell, Seriennummer und Kapazität nennst du nur, wenn sie lesbar sind, und übernimmst sie wörtlich. Erfinde diese Angaben niemals.

Ist ein Produkt in seiner Verpackung zu sehen, nimm die Verpackung als Hinweis und beschreibe das Produkt darin. Nur wenn die Verpackung selbst der Gegenstand ist, etwa ein leerer Karton, nennst du sie so.

Schreibe alles auf Deutsch.
- `title`: Marke, Modell und Art des Gegenstands in wenigen Worten, soweit bekannt.
- `description`: ein bis zwei sachliche Sätze dazu, was es ist und wofür man es benutzt. Farbe oder Material nur, wenn sie den Gegenstand unterscheiden. Keine Feature-Listen von der Verpackung, keine Werbesprache, keine Bauteilnummern. Den Zustand erwähnst du nur, wenn der Gegenstand offensichtlich defekt ist.
- `search_terms`: Liste mit Wörtern, die jemand eintippen würde, um den Gegenstand zu finden: Synonyme, die allgemeine Kategorie und Schreibweisen ohne Sonderzeichen oder ohne Leerzeichen, zum Beispiel `Rode` für RØDE und `Pi5` für Raspberry Pi 5.
```

- [ ] **Step 4: Run the tests**

Same command as Step 2. Expected: PASS.

- [ ] **Step 5: Tune against Gemma**

Run the eval as in Task 5 Step 8, read the report and its texts, change the wording, repeat with `--runs=3` when a change looks good. Stop when results no longer improve from wording changes; the prompt rules in the spec stay fixed, and the prompt is not bent to Gemma's quirks. Commit each kept version: "Tune the extraction prompt".

- [ ] **Step 6: Stop and ask the user**

Show baseline and latest Gemma numbers plus the generated texts. Ask for the go for the one OpenAI run. Do not proceed without it.

- [ ] **Step 7: After the go, run against OpenAI**

Run: `docker compose exec app php artisan extraction:eval --provider="OpenAI"`
Show the user the report. The user judges the texts and decides if more tuning is needed. Repeat only with a new go per run.

- [ ] **Step 8: Update `CLAUDE.md`**

Under Architecture: `search_terms` on imports and items (jsonb, part of keyword search through array elements and of `textFor()`); "Neu generieren" with `RegenerateTexts` and its state columns; add `RegenerateTexts` to the queue timing line. Under Running commands: `extraction:eval --provider=…`, with the note on `tests/Evals/extraction/` and that it is not part of the suite. Under Embeddings, change "when title or description change" to include search terms.

- [ ] **Step 9: Final verification**

Run: `docker compose exec app php artisan test --compact && docker compose exec app ./vendor/bin/pint --dirty --format agent && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all pass.

- [ ] **Step 10: Commit** — "Rewrite the extraction prompt and document search terms and eval"
