# German and English Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tory works in German and English: each user picks the interface language, the household picks the language the AI writes item texts in.

**Architecture:** A `Language` enum, `users.locale` and a single-row `household_settings` table hold the choices; a `SetLocale` middleware resolves the locale per request (user, then browser for users without a choice, then household for guests, then browser on a fresh install). Every user-facing string goes through `__()` with English text as key and German in `lang/de.json`, guarded by a completeness test. The extractor keeps its German prompt unchanged and gains an English one, chosen by the content language.

**Tech Stack:** Laravel 13, Filament 5, Livewire, Pest, Postgres, `laravel/ai`, `laravel/mcp`.

**Spec:** `docs/superpowers/specs/2026-11-21-german-english-design.md`

## Global Constraints

- Branch `feature/TOR-40-german-english`, one pull request for everything.
- Everything runs in Docker: `docker compose exec app php artisan test --compact …`, `docker compose exec app ./vendor/bin/pint --dirty`, `docker compose exec app ./vendor/bin/phpstan analyse`. After adding a migration also run `docker compose exec app php artisan migrate` on the dev database.
- `Language` cases: `German = 'de'`, `English = 'en'`; labels „Deutsch“ and „English“, never translated.
- English source strings are the keys; German lives in `lang/de.json` only. No new dependency, no PHP key files.
- Date display formats: `de` → `d.m.Y H:i` (unchanged), `en` → `j M Y, H:i`.
- `APP_LOCALE` stays `de`; `fallback_locale` stays `en`.
- The German prompt of `InventoryExtractor` (instructions, schema descriptions, user prompt) stays byte-for-byte unchanged.
- `PhotoBoundaryChecker` stays German; the embedding prefix `Suchbegriffe:` stays.
- Not translated: Artisan output, eval reports, logs, exception messages that never reach a user, user input.
- English vocabulary (fixed, every word is a key): Item/Items, Spot, Location, Label, Import, Bulk import, Search term, Note, AI providers („Eigene KI“), Claude connection, Tory in Claude, Add item („Erfassen“), Create item („Übernehmen“), Regenerate („Neu generieren“), Invite link, First-time setup, Locked.
- Tests stay German: requests carry `Accept-Language: de`, `UserFactory` sets `locale` to `de`. Never assert a literal id.
- Write the failing test first; run Pint and PHPStan before each commit; commit messages end with `(TOR-40)` and the `Co-Authored-By` line.

## Review Focus

- A user whose `locale` is `null` and whose browser sends `Accept-Language: fr` gets English, not German and not an error (Task 2).
- An unauthenticated API call (`POST /api/v1/auth/token` with wrong credentials) on a German household answers in German even with `Accept-Language: en` (Task 2).
- A string with a placeholder (`Höchstens :max Fotos.`) renders the value in both languages; a key missing from `lang/de.json` falls back to English instead of breaking (Task 4, completeness test plus one placeholder assertion).
- An English user opening every panel page sees no German UI text (Task 10, umlaut smoke test).
- Changing the content language dispatches no job and leaves every item's title, description and search terms untouched (Task 11).

---

### Task 1: Language enum, user locale, household settings

**Files:**
- Create: `app/Enums/Language.php`
- Create: `database/migrations/2026_11_21_100000_add_locale_to_users_table.php`
- Create: `database/migrations/2026_11_21_100100_create_household_settings_table.php`
- Create: `app/Models/HouseholdSettings.php`
- Modify: `app/Models/User.php` (cast, `@property`), `database/factories/UserFactory.php`
- Test: `tests/Feature/Models/HouseholdSettingsTest.php`, `tests/Feature/Migrations/UserLocaleMigrationTest.php`

**Interfaces:**
- Produces: `enum Language: string implements HasLabel { case German = 'de'; case English = 'en'; }`; `User::$locale: ?Language` (cast, not fillable); `HouseholdSettings::current(): self`; `HouseholdSettings::$content_language: Language` (cast); `HouseholdSettings::changeContentLanguage(Language $language): void`.

- [ ] **Step 1: Write the failing tests**

`HouseholdSettingsTest`: `it returns the single row created by the migration with German` → `HouseholdSettings::current()->content_language === Language::German` and `HouseholdSettings::query()->count() === 1`; `it changes the content language` → after `changeContentLanguage(Language::English)`, `current()->content_language === Language::English` and the count is still 1.

`UserLocaleMigrationTest` (follow the existing tests in `tests/Feature/Migrations/` for running a single migration down and up): a user inserted before the `add_locale` migration has `locale = 'de'` afterwards; a user created afterwards without a locale has `null`.

- [ ] **Step 2: Run them, expect FAIL** (`Class "App\Enums\Language" not found`)

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/HouseholdSettingsTest.php tests/Feature/Migrations/UserLocaleMigrationTest.php`

- [ ] **Step 3: Implement**

- `php artisan make:enum Language --no-interaction`, move it to `app/Enums/`.
- Users migration: nullable string `locale` after `theme_mode`; `up()` then runs `DB::table('users')->update(['locale' => 'de'])`.
- Household migration: `uuid('id')->primary()`, `string('content_language')`, timestamps; inserts one row with a new UUIDv7 (`Str::uuid7()`) and `de`.
- `HouseholdSettings`: `HasUuids`, table `household_settings`, cast `content_language` to `Language`, nothing fillable; `current()` is `self::query()->sole()`; `changeContentLanguage()` uses `forceFill(...)->save()`.
- `UserFactory::definition()` gets `'locale' => Language::German`.

- [ ] **Step 4: Run them, expect PASS**; run `php artisan migrate` on dev.

- [ ] **Step 5: Commit** — `Store interface and content language (TOR-40)`

---

### Task 2: Resolve the locale per request

**Files:**
- Create: `app/Http/Middleware/SetLocale.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (panel middleware, after `AuthenticateSession`), `bootstrap/app.php` (`web` append, `api` append), `routes/ai.php` (`/mcp` middleware after `EnsureUserIsActive`), `app/Models/User.php` (`createFirstAdministrator`), `tests/TestCase.php`
- Test: `tests/Feature/Http/SetLocaleTest.php`, `tests/Feature/Panel/SetUpFirstAdministratorTest.php` (extend)

**Interfaces:**
- Consumes: `Language`, `User::$locale`, `HouseholdSettings::current()`.
- Produces: after the middleware, `app()->getLocale()` is `'de'` or `'en'`.

- [ ] **Step 1: Write the failing tests**

Use a tiny test-only route registered in the test (`Route::middleware(['web'])->get('/_locale', fn () => app()->getLocale())`) plus real pages:

- `it uses the user's locale` — user with `locale = English`, `Accept-Language: de` → `en`.
- `it follows the browser for a user without a locale` — `locale = null`, `Accept-Language: de-DE,de;q=0.9` → `de`; with `fr` → `en`.
- `it uses the household language for guests` — a user exists, household `de`, guest with `Accept-Language: en` → `de`; household `en` → `en`.
- `it follows the browser on a fresh install` — no user, `Accept-Language: en` → `/setup` shows `Welcome to Tory`; with `de` → `Willkommen bei Tory`.
- `it answers the token endpoint in the household language` — wrong credentials on `POST /api/v1/auth/token` with `Accept-Language: en`, household `de` → message from `lang/de/auth.php` (`auth.failed`).
- `it answers API requests in the token owner's language` — Sanctum user with `locale = English`, a `422` from an item update with an empty title → English validation message.
- Extend `SetUpFirstAdministratorTest`: submitting `/setup` with `Accept-Language: en` leaves `HouseholdSettings::current()->content_language === Language::English`.

- [ ] **Step 2: Run them, expect FAIL**

- [ ] **Step 3: Implement**

`SetLocale::handle()` resolves in the spec's order: `$request->user()?->locale` → if a user is signed in with `null`, `$request->getPreferredLanguage(['en', 'de'])` → if `User::query()->exists()`, `HouseholdSettings::current()->content_language` → otherwise the browser. Calls `app()->setLocale()`. Laravel's middleware priority already puts it after `auth:*`.
`createFirstAdministrator()` calls `HouseholdSettings::current()->changeContentLanguage(Language::from(app()->getLocale()))` inside its transaction.
`TestCase::setUp()` adds `$this->withHeader('Accept-Language', 'de')`.

- [ ] **Step 4: Run the new tests and the whole suite, expect PASS** (`docker compose exec app php artisan test --compact`)

- [ ] **Step 5: Commit** — `Resolve the locale per request (TOR-40)`

---

### Task 3: Language switch and date formats

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (`userMenuItems`), `app/Providers/AppServiceProvider.php` (`configureDefaults`)
- Test: `tests/Feature/Panel/LanguageSwitchTest.php`

**Interfaces:**
- Consumes: `User::$locale`, `SetLocale`.

- [ ] **Step 1: Write the failing tests**

- German user sees the menu item `English`; English user sees `Deutsch`.
- Calling the action (Livewire test on Filament's topbar component, or the page that owns the user menu) sets the user's `locale` to the other language.
- A table date renders `21.11.2026 14:30` for a German user and `21 Nov 2026, 14:30` for an English user (an item with a fixed `created_at` in the item list).

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement**

A user-menu `Action::make('language')` labelled with the other language's `getLabel()`, icon `Heroicon::OutlinedLanguage`, `->action()` saving `locale` via `forceFill` and reloading the page with `$livewire->js('window.location.reload()')`. If Filament 5's user menu cannot run actions, fall back to a `POST /locale` route next to `/theme` following `SaveThemeMode` (spec leaves this open).
`configureDefaults()` passes a closure that picks the format from `app()->getLocale()`.

- [ ] **Step 4: Run, expect PASS**

- [ ] **Step 5: Commit** — `Switch the interface language from the user menu (TOR-40)`

---

### Task 4: Completeness test and `lang/de.json`

**Files:**
- Create: `lang/de.json` (`{}` plus the keys of this task), `tests/Feature/TranslationsTest.php`
- Modify: `app/Http/Controllers/Api/V1/AuthController.php` only if needed to keep `auth.failed` working (it uses a PHP key; leave it)

**Interfaces:**
- Produces: the rule every later task obeys: each `__('…')` literal in `app/` and `resources/views/` has a key in `lang/de.json`, and each key in `lang/de.json` is used.

- [ ] **Step 1: Write the tests**

- `every translated string has a German translation` — scan `app/**/*.php` and `resources/views/**/*.blade.php` for `__('…')` / `__("…")` literals (skip keys containing a `.` without a space, such as `auth.failed`, which live in PHP files); assert the missing list is `[]`.
- `every German translation is used` — the reverse; assert the unused list is `[]`.
- [ ] **Step 2: Run** — both pass on today's code (there are no literals yet besides `auth.failed`).

- [ ] **Step 3: Commit** — `Check that every string has a German translation (TOR-40)`

---

### Tasks 5–9: The string sweep

Each task converts one area to `__()` with English keys, adds the German texts to `lang/de.json` (exactly today's wording), keeps `TranslationsTest` and the area's existing tests green, and adds one English assertion per area to an existing test file (an English user sees the area's main heading in English). Use the vocabulary from Global Constraints. Static properties that cannot call `__()` (`$modelLabel`, `$navigationLabel`, `navigationGroup`) become the matching `get…()` methods. Placeholders use `:name` syntax.

Steps for each: (1) add the English assertion, run it, expect FAIL; (2) convert the area; (3) run the area's tests plus `TranslationsTest`, expect PASS; (4) Pint, PHPStan, commit `Translate <area> (TOR-40)`.

- [ ] **Task 5: Items, imports and bulk imports** — `app/Filament/Resources/{Items,Imports,BulkImports}/**`, `app/Filament/Actions/RegenerateTextsAction.php`, `app/Filament/Schemas/*`, `resources/views/filament/resources/bulk-imports/view.blade.php`, `resources/views/filament/forms/components/*`. English assertion in `tests/Feature/Panel/ItemResourceTest.php`.
- [ ] **Task 6: Spots, locations, labels** — `app/Filament/Resources/{Spots,Locations}/**`, `app/Filament/Actions/SpotLabelsAction.php`, `resources/views/pdf/spot-labels.blade.php` (`lang` attribute from `app()->getLocale()`). English assertion in the spot resource test.
- [ ] **Task 7: Users, AI providers, Tory in Claude, auth pages, panel provider** — `app/Filament/Resources/{Users,AiProviders}/**`, `app/Filament/Pages/*`, `app/Filament/Auth/*`, their views, `resources/views/mcp/authorize.blade.php` (`lang` attribute), navigation group „Inventar“/„Einstellungen“ and the `Version :version` menu item in `AdminPanelProvider`. English assertion in `tests/Feature/Panel/AiProviderResourceTest.php` and the setup test from Task 2.
- [ ] **Task 8: Dashboard, widgets and JS messages** — `app/Filament/Widgets/*`, their views, and the six messages in `resources/js/{bulk-photos,item-photos,photo-upload}.js`: the Blade views that create the Alpine components pass `messages` (`tooMany`, `uploadFailed`, `unreadable`) in the config object next to `maxPhotos`, the JS reads them and replaces `:max` itself. English assertion: the item search widget's placeholder (`Where is …?`). Also add to `TranslationsTest`: `placeholders render in both languages` — the server-side photo limit message `__('At most :max photos.', ['max' => 20])` is `Höchstens 20 Fotos.` under `de` and `At most 20 photos.` under `en`.
- [ ] **Task 9: Enums, models, HTTP and AI failure messages** — `getLabel()` of `ImportStatus`, `BulkImportStatus`, `UserRole`, `UserStatus`, `LabelSize`, `GroupMark`; `ProviderFailureReason::message()`; user-facing strings in `app/Models` (`Import::failureMessage()` fallback „Der KI-Provider“ and similar), `app/Http` (abort and validation messages, `ValidatesPhotoUploads`), and user-facing `RuntimeException` texts that end up in `failure_message`. English assertions: `ImportStatus::Failed->getLabel()` under `en` is `Failed`; `GET /api/v1/imports/{id}` for an English token owner returns an English `status_label`.

---

### Task 10: No German left in the English interface

**Files:**
- Test: `tests/Feature/Panel/EnglishInterfaceTest.php`

- [ ] **Step 1: Write the test** — as an English administrator, with one location, spot, item, import and bulk import from factories whose texts contain no umlauts, request every panel page (dashboard, each resource's index, create, view and edit page, the household page, Tory in Claude, profile) and assert the response body's visible text (strip tags) matches no `/[äöüÄÖÜß]/`. Also request `/login` as a guest with household `en`.
- [ ] **Step 2: Run** — fix every hit by converting the string it finds (this is the sweep's safety net), until PASS.
- [ ] **Step 3: Commit** — `Check the English interface for German leftovers (TOR-40)`

---

### Task 11: Household page

**Files:**
- Create: `app/Filament/Pages/HouseholdSettingsPage.php` (+ view if a schema page needs one; follow Filament 5's settings-page pattern via `filament-development` skill)
- Test: `tests/Feature/Panel/HouseholdSettingsPageTest.php`

**Interfaces:**
- Consumes: `HouseholdSettings::current()`, `changeContentLanguage()`, `Language`.

- [ ] **Step 1: Write the failing tests**

- an administrator sees „Haushalt“ in the navigation group „Einstellungen“ and the page with the Select „Inhaltssprache“ preset to `Deutsch`;
- a plain user gets 403 and sees no navigation entry;
- saving `en` changes `HouseholdSettings::current()->content_language`, shows a success notification, and with `Queue::fake()` pushes nothing; an existing item's `title`, `description` and `search_terms` stay unchanged.

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement** — `canAccess()` is `auth()->user()?->isAdmin()`; labels `__('Household')`, `__('Content language')`; helper text `__('Applies to new captures and to “Regenerate” only. Existing items stay as they are.')` (German: „Gilt nur für neue Erfassungen und „Neu generieren“. Bestehende Gegenstände bleiben, wie sie sind.“). Options from `Language::cases()`.

- [ ] **Step 4: Run, expect PASS**

- [ ] **Step 5: Commit** — `Set the content language on the household page (TOR-40)`

---

### Task 12: Extraction in the content language

**Files:**
- Modify: `app/Ai/Agents/InventoryExtractor.php`, `app/Jobs/ExtractImport.php`, `app/Jobs/RegenerateTexts.php`, `app/Ai/ProviderTester.php`
- Test: `tests/Feature/Ai/InventoryExtractorTest.php`, `tests/Feature/Jobs/ExtractImportTest.php`

**Interfaces:**
- Consumes: `Language`, `HouseholdSettings::current()`.
- Produces: `InventoryExtractor::__construct(public ?AiProvider $aiProvider = null, public Language $language = Language::German)`; `InventoryExtractor::make(aiProvider: …, language: …)`.

- [ ] **Step 1: Write the failing tests**

- `it keeps the German prompt` — `instructions()` for `Language::German` equals the current text (copy today's heredoc into the test as the expected string) and contains `Schreibe alles auf Deutsch.`
- `it writes English for English` — `instructions()` for `Language::English` contains `Write everything in English.` and no `Deutsch`; the schema descriptions and the prompt passed to the provider are English (assert with `InventoryExtractor::fake()` recording the prompt).
- `ExtractImportTest`: with household `en`, the job builds the extractor with `Language::English` (assert through the faked prompt).

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement** — `instructions()`, `schema()` and the prompt in `extract()` pick the German or English text by `$this->language`; the English instructions carry the same rules (everyday English word, no invented brand data, packaging rule, about eight search terms as single plain English words, spelling variants such as `Citroen`/`Hero12`, bare JSON object). The model's error message for an incomplete answer becomes `__()`. The three callers pass `language: HouseholdSettings::current()->content_language`; `ExtractionEval` gets it in Task 13.

- [ ] **Step 4: Run, expect PASS**

- [ ] **Step 5: Commit** — `Extract item texts in the content language (TOR-40)`

---

### Task 13: Eval per language

**Files:**
- Modify: `app/Console/Commands/EvalExtraction.php`, `app/Ai/Eval/EvalCase.php`, `app/Ai/Eval/ExtractionEval.php`, `tests/Evals/extraction/cases.json`
- Test: `tests/Feature/Ai/EvalCaseTest.php`, `tests/Feature/Console/EvalExtractionCommandTest.php`

**Interfaces:**
- Produces: `EvalCase::loadAll(string $directory, Language $language): list<EvalCase>`; `ExtractionEval::run(AiProvider $provider, array $cases, Language $language): EvalRun`; option `--language=de` (default) or `en`.

- [ ] **Step 1: Write the failing tests**

- `EvalCaseTest`: a case file with `"de": {"search_words": …, "title_words": …, "forbidden_words": …}` and `"en": {…}` loads the German words for `de` and the English words for `en`; a case missing the requested language fails with a readable message.
- `EvalExtractionCommandTest`: `--language=en` passes `Language::English` to the extractor (faked); `--language=fr` fails listing `de, en`.

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement** — move each case's three word lists under `"de"` unchanged and add `"en"` with English words for the same photos (RØDE Wireless GO: title `Microphone`, search `microphone`, `rode`; forbidden `packaging`, `box`, `signs of wear`, `damaged`; and likewise for the other three cases).

- [ ] **Step 4: Run, expect PASS**

- [ ] **Step 5: Commit** — `Run the extraction eval per language (TOR-40)`

---

### Task 14: MCP in English

**Files:**
- Modify: `app/Mcp/Servers/ToryServer.php`, `app/Mcp/Tools/{CreateSpotTool,FindItemsTool,FindSpotsTool,MoveItemTool}.php`
- Test: `tests/Feature/Mcp/MoveItemToolTest.php`, `tests/Feature/Mcp/FindItemsToolTest.php`

- [ ] **Step 1: Write the failing tests** — an English token owner gets `Nothing found.` from `FindItemsTool` and `… is now on …` from `MoveItemTool`; a German one keeps today's texts.
- [ ] **Step 2: Run, expect FAIL**
- [ ] **Step 3: Implement** — `#[Instructions]`, every `#[Description]` and schema description rewritten in English with the same meaning (search first; ask when several or no matches; ask before creating a spot); `Response::text` and `Response::error` texts through `__()`.
- [ ] **Step 4: Run all MCP tests, expect PASS**
- [ ] **Step 5: Commit** — `Speak English to Claude, answer in the user's language (TOR-40)`

---

### Task 15: Documentation and release check

**Files:**
- Modify: `CLAUDE.md`, `CONTEXT.md`, `README.md`

- [ ] **Step 1: Docs**
  - `CLAUDE.md`: replace the "German UI" line with: interface language per user (`users.locale`, `SetLocale`), content language per household (`HouseholdSettings`, page „Haushalt“), English keys in `lang/de.json`, `TranslationsTest` and `EnglishInterfaceTest`; dates per locale. Add `--language` to the `extraction:eval` paragraph and the command list. Add the gotcha: `TestCase` sends `Accept-Language: de`.
  - `CONTEXT.md`: the opening says the interface is German and English and each term gives the German UI word; add **Interface language** (Sprache der Oberfläche) and **Content language** (Inhaltssprache); for Provider note the English UI word "AI providers"; check every other entry still fits.
  - `README.md`: one short paragraph on the two languages and the household page.
- [ ] **Step 2: Full check** — `docker compose exec app php artisan test --compact`, Pint, PHPStan: all green.
- [ ] **Step 3: Eval (release criterion)** — `docker compose exec app php artisan extraction:eval --provider="Ollama lokal" --runs=3` on `master` (baseline) and on the branch with `--language=de` and `--language=en`. German no worse than the baseline, English roughly as good as German. Save the three report paths for the PR description. This calls the local model only; if the user names a paid provider instead, ask first.
- [ ] **Step 4: Browser check** — with `playwright-cli` on `localhost:8000`: switch to English and back, change the content language, add an item in English.
- [ ] **Step 5: Commit** — `Describe German and English (TOR-40)`
