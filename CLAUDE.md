# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Tory

Vocabulary lives in `CONTEXT.md`.

Laravel 13 + Filament 5 inventory app running on FrankenPHP (PHP 8.5) with Postgres 18, developed entirely in Docker. The Filament panel is the whole app and is served at `/`. Feature design docs and implementation plans live in `docs/superpowers/specs/` and `docs/superpowers/plans/` (one pair per feature, dated).

## Running the app

- `docker compose up -d` starts three services: `app` (FrankenPHP + queue worker + scheduler under supervisord, http://localhost:8000), `db` (Postgres 18 with the `pgvector` extension, image `pgvector/pgvector:pg18-trixie`, port 5432), `vite` (Vite dev server, http://localhost:5173; idle until a custom Filament theme exists).
- The project directory is bind-mounted into `app` and `vite` at `/app`. Edit files on the host; no rebuild needed.
- The app entrypoint (`docker/entrypoint.sh`) creates `.env`, generates `APP_KEY`, runs `composer install` when `vendor/` is missing, and runs migrations when `AUTO_MIGRATE=true` (set in `compose.yaml`).
- Rebuild the image after changing `docker/` (`docker compose build app && docker compose up -d app`); recreate the container after changing `compose.yaml` (`docker compose up -d app`). The `app` service has a 4-minute stop grace period so a running extraction job can finish; a hard kill leaves an import on "Läuft" until the queue's `retry_after` (300 s) fails it.

## Running commands

Everything PHP runs inside the `app` container; everything npm runs inside the `vite` container. Never use host PHP/Composer/npm. Tests need the `tory_testing` database on the `db` service (see Testing conventions).

```sh
docker compose exec app php artisan <command>
docker compose exec app composer <command>
docker compose exec app php artisan test --compact                                  # whole Pest suite
docker compose exec app php artisan test --compact tests/Feature/Panel/ViewImportTest.php          # one file
docker compose exec app php artisan test --compact tests/Feature/Panel/ViewImportTest.php --filter=retry  # one test
docker compose exec app ./vendor/bin/pint --dirty    # formatter (changed files)
docker compose exec app ./vendor/bin/phpstan analyse # Larastan
docker compose exec app php artisan make:filament-user --name=Admin --email=admin@example.com --password=secret
# CLI-created users are unverified; mark one verified with:
docker compose exec app php artisan tinker --execute='App\Models\User::firstWhere("email", "admin@example.com")->markEmailAsVerified();'
docker compose exec app php artisan queue:failed     # database queue; failed jobs land in failed_jobs
docker compose exec app php artisan extraction:eval --provider="Ollama lokal" --runs=3  # measure the extraction prompt; --provider is required
docker compose exec vite npm <command>
docker compose exec db psql -U tory -d tory
```

Supervised processes: `docker compose exec app supervisorctl status` (frankenphp, queue, scheduler). Logs: `docker compose logs -f app`. Mail uses the `log` driver in development; verification and reset links are in `storage/logs/laravel.log`.

`extraction:eval` runs the real extractor against the cases in `tests/Evals/extraction/` (`cases.json` plus photos), which are not part of the test suite. It writes nothing to the database and saves its report under `storage/app/private/evals/`. It calls the named provider for real, so a paid provider costs money per run.

Generator quirk: `php artisan make:enum` writes to `app/`, not `app/Enums/`; move the file by hand.

## Architecture

- **Panel:** configured in `app/Providers/Filament/AdminPanelProvider.php` (id `admin`, path `''`). Everything under `app/Filament/` is auto-discovered. `routes/web.php` must never define `/`. Auth is Filament's (login, registration, password reset, email verification, profile); `App\Models\User` implements `FilamentUser` (every user may access the panel) and `MustVerifyEmail`. There is no policy layer: every verified user sees every record (single household).
- **Resource layout:** a resource is a directory, e.g. `app/Filament/Resources/Imports/` with `ImportResource.php`, `Schemas/ImportForm.php` (create form), `Schemas/ImportInfolist.php` (view page), `Tables/ImportsTable.php` and `Pages/{List,Create,View}Import.php`. Status enums in `app/Enums/` implement Filament's `HasLabel`/`HasColor` so a `TextEntry`/`TextColumn` `->badge()` renders German labels and colours directly. UI copy is German.
- **Import pipeline (the core feature):** "Importe" → "Erfassen" (`/imports/create`) uploads 1–6 photos to the private `local` disk under `imports/`, creates an `App\Models\Import` as `pending` and dispatches `app/Jobs/ExtractImport.php`. The job marks the import `running`, sends the photos to the structured-output agent `app/Ai/Agents/InventoryExtractor.php`, and stores a German `title`, `description` and `search_terms` (or, via `failed()`, the `error`). The view page (`/imports/{id}`) polls with `wire:poll.5s` while the import is pending/running, has a Retry action on failed imports, and shows the raw error only when `app.debug` is on (a failure the user can fix on "Eigene KI" shows a fixed German sentence with a link instead, see AI providers). A completed or failed import is turned into an item via "Übernehmen" on its view page; "Löschen" removes an import and its photos.
- **Spots:** every `Spot` ("Lagerplatz") gets a random `code` on creation: 4 characters from digits and uppercase letters without `0`, `O`, `1`, `I` (`CODE_ALPHABET`, about 1 million codes; unique index). It is what users see (`label` accessor: `{code} · {name}`) and the route key; the bigint `id` stays internal and carries the foreign keys. Neither code nor address may ever change (`code` is not fillable). `/spots/{code}` is the future QR target: its view page lists the items inside (relation manager). The resource accepts the code in any case; anything else answers 404. The shared `SpotSelect` picks one; `users.last_spot_id` preselects the user's last choice on the import form and in "Übernehmen". Items have a NOT NULL spot (restrict on delete).
- **Items:** `Item` (title, description, `photos`, nullable `embedding` vector(1024)) is created only by `Import::convertToItem()` ("Übernehmen" on the import view page); there is no create page. Photo files belong to exactly one import or one item: conversion deletes the import row first with a query-builder delete (no model event, so the files are kept; they now belong to the item) and throws `ModelNotFoundException` when the row is already gone (a second "Übernehmen" answers 404 and creates nothing); "Übernehmen" also stores the chosen spot as the user's `last_spot_id`; any plain delete of an import or item removes its files. Delete exists only on view pages. Global search is disabled panel-wide (`record title` attributes only feed headings). Navigation order: Gegenstände, Lagerplätze, Importe, Eigene KI.
- **Embeddings:** `App\Ai\ItemEmbedder` (provider/model from `ai.inventory.embedding_provider`/`embedding_model`, env `INVENTORY_EMBEDDING_PROVIDER`/`INVENTORY_EMBEDDING_MODEL`, defaults `ollama`/`bge-m3`; throws unless the vector has 1024 dimensions) is called by `App\Jobs\EmbedItem`, dispatched from `Item` model events when title, description or search terms change. Job and command share `ItemEmbedder::embedAndStore()`, which stores the vector (row-locked, no model events, `updated_at` untouched) only if the item's text is still the embedded text. The job uses a 45 s client timeout (job timeout 60). `items:embed {--all}` (scheduled hourly) catches up items without an embedding and stops early after 3 consecutive failures; `--all` recomputes every item. Run `ollama pull bge-m3` once on the Ollama host. Changing the dimension needs a migration of the `embedding` column plus `items:embed --all`. Needs the `vector` extension, so `db` is `pgvector/pgvector:pg18-trixie`.
- **Search terms:** `search_terms` on imports and items (`jsonb` array of plain strings, not null, default empty) are suggested by the extractor, shown as badges on view pages and edited as tags ("Suchbegriffe") in "Übernehmen" and on the item edit page. `InventoryExtractor::extract()` returns the DTO `ExtractedTexts` and cleans the terms; missing terms give an empty list and do not fail an import. Terms are part of keyword search (matched per array element, not on the JSON text) and of `ItemEmbedder::textFor()`; with empty terms that text is unchanged, so old embeddings stay valid. The prompt has all providers describe the thing rather than the photo or its packaging, name its kind even if unprinted, and never invent brand, model, serial number or capacity; judge changes to it with `extraction:eval`.
- **Neu generieren:** an action on the view page of items and completed imports (hidden without photos) dispatches `App\Jobs\RegenerateTexts`, which replaces title, description and search terms using the active provider or the `.env` fallback. State columns on both tables: `regenerating_at`, `regeneration_error`, `regeneration_failure_reason`, `regeneration_failure_provider` (trait `RegeneratesTexts`). While it runs, the page shows "Wird neu generiert …" and polls, and "Neu generieren", "Bearbeiten" and "Übernehmen" are hidden. On failure the old texts stay and the page shows the reason as for imports. There is no bulk variant and no backfill, because each press may cost money.
- **Search:** `App\Ai\ItemSearch::apply($query, $term)` (list search box): items whose title/description/search terms contain every word come first, then semantic matches with cosine similarity >= `ai.inventory.search_min_similarity` (`INVENTORY_SEARCH_MIN_SIMILARITY`, default 0.5; tune if unrelated items show up or related ones are missing). If embedding the query fails (3 s timeout) the error is reported and only the keyword match applies, so search works without Ollama/bge-m3.
- **Queue timing invariant:** job `$tries = 1`, `$timeout = 200`; supervisord runs the worker with `--timeout=210` (dev uses `queue:listen`, which kills the child at its own timeout regardless of the job's); the database queue `retry_after` defaults to 300 (`DB_QUEUE_RETRY_AFTER`). `RegenerateTexts` also has `$tries = 1`, `$timeout = 200`. `EmbedItem` has `$tries = 3`, `$timeout = 60`. Keep every job timeout < 210 < 300 when changing any of them. Dispatch happens inside Filament's create transaction.
- **AI providers ("Eigene KI"):** `App\Models\AiProvider` (vendor enum `AiVendor`: Ollama, Claude/`anthropic`, OpenAI, Mistral; name unique; `model`; `url` for Ollama only) is managed on `/ai-providers` (`AiProviders` resource, one `ManageAiProviders` page with the providers as cards, create/edit in modals). At most one is active, enforced by the partial unique index `ai_providers_one_active`; `activate()` locks all rows and clears the old one first. A new provider is never activated automatically, and nothing ever switches or fails over on its own. The active one cannot be deleted (model `deleting` hook, disabled action), so the UI has no way back to the `.env` fallback. `api_key` is `encrypted` with `APP_KEY` (changing it makes keys unreadable; the card shows a warning and imports/tests report `KeyUnreadable`), hidden from serialisation, never filled into the edit form (empty field keeps the key); the card shows `key_hint` (last 4 characters, saved with the key, so rendering never decrypts). `ProviderModels::for()` fetches model suggestions (Ollama `/api/tags`, others `/models`; 5 s timeout, cached 60 s; `null` on failure, free text always works). `ProviderTester` ("Testen") runs the real extractor on `resources/ai/test-image.png` with a 120 s timeout and returns a German reason or null. `ProviderFailure` wraps any exception of a job that ran on an `AiProvider`: the message is scrubbed of the key, the original exception is not kept as `previous`, and `ProviderFailureReason::fromThrowable()` classifies it (key rejected, key unreadable, unknown model, images unsupported, unreachable; `null` otherwise). `ExtractImport::failed()` stores `failure_reason`/`failure_provider` on the import; the view page then shows e.g. "Claude hat den API-Key abgelehnt. Provider prüfen" linking to "Eigene KI" even with `app.debug` off, and the general message for unclassified failures. `ProviderTransfer` moves providers between installations as JSON with plain text keys ("Exportieren"/"Importieren" on the page, because stored keys depend on `APP_KEY`): `export()` returns a `ProviderExport` of `ProviderTransferEntry` items and `import()` a `ProviderImportResult`; a provider is identified by its UUID key (the file format calls it `uuid`), an existing uuid is skipped without change, a new uuid with a taken name becomes "Name (Import)", a file is imported completely or not at all, and neither direction carries the active state; the page deletes the upload afterwards. Open risk before going public: self-registration is on, so any verified registrant could download all stored keys in plain text, spend them and change the active provider; decide (e.g. turn registration off) first.
- **AI:** `laravel/ai` with Anthropic as the app default (`config/ai.php`, `ANTHROPIC_API_KEY`). The extractor runs on `AiProvider::active()`, read fresh in every `ExtractImport` run (the worker is long-lived); only with no active provider does it read its own provider/model from `config('ai.inventory.*')` (`INVENTORY_AI_PROVIDER`, `INVENTORY_AI_MODEL`) as before. Embeddings are unaffected. For the `.env` fallback with local Ollama set `INVENTORY_AI_PROVIDER=ollama`, `INVENTORY_AI_MODEL=gemma4:e4b-mlx`, `OLLAMA_URL=http://host.docker.internal:11434`. The extractor's instructions ask for a bare JSON object because `gemma4` ignores Ollama's `format` schema and otherwise prefixes `json`, which fails decoding. `app/Ai/Agents/Assistant.php` is an unused stub.
- **Assets/styling:** Filament's precompiled theme; generated assets in `public/{css,js,fonts}/filament` are gitignored and refreshed by `filament:upgrade` on `composer dump-autoload` (run `php artisan filament:assets` after a manual upgrade). `resources/css/app.css` is a Tailwind 4 entry nothing loads yet; add a theme with `php artisan make:filament-theme admin` when needed. No app-level Blade layout exists, so a `Route::livewire()` full-page component would fail until `php artisan livewire:layout` creates one. Livewire's `make_command.emoji` is off.

## Code conventions

- **Primary keys:** tables use UUIDs (`HasUuids`, version 7) unless explicitly specified otherwise. `Spot` is such an exception (internal bigint `id` plus the visible `code`); framework tables such as `jobs` stay as they are. `HasUuids` answers 404 for a route key that is no UUID.
- **DTOs over arrays:** pass and return structured data as DTOs, not arrays. Arrays remain where a framework API demands them (e.g. Filament schemas, `$fillable`, casts).
- **Constructor injection:** inject dependencies through the constructor instead of calling `resolve()` or `app()` in the code. Where the framework rules out a constructor, use its own injection: typed parameters on Filament closures (`->action(fn (ProviderTransfer $transfer) => ...)`), `$this->app` in service providers. Logic that needs a service does not belong on a model; move it to a class of its own (`ItemSearch`).

## Testing conventions

- Pest on the Postgres database `tory_testing` (`phpunit.xml`; created by `docker/postgres/init/` on a fresh volume, otherwise once with `docker compose exec db createdb -U tory tory_testing`). `TestCase::guardTestingDatabase()` runs on every app boot and refuses any other database, so the suite can never refresh `tory`. Postgres sequences are not reset between tests: never assert a literal id; `RefreshDatabase` is applied to every Feature test in `tests/Pest.php`.
- Panel pages: `Livewire::test(PageClass::class, ['record' => $id])->fillForm([...])->call('create')`, `->callAction('retry')`, `->assertActionHidden(...)`, `->assertCanSeeTableRecords([...], inOrder: true)`. Use `Storage::fake('local')` for uploads and `Queue::fake()` unless the job itself is under test. `TestCase::setUp()` calls `Embeddings::fake()`, so no test reaches a real embedding provider; override it with `Embeddings::fake([...])` or a throwing closure to test `ItemEmbedder`, `EmbedItem` and search.
- AI providers: with an `AiProvider::factory()->active()` in a job test, failures are wrapped in `ProviderFailure` (scrubbed message, no `previous`), so assert on `failure_reason`/`failure_provider` rather than the raw exception; with none, the `.env` path and raw errors apply. Model lists and vendor errors are tested with `Http::fake()`; never assert a key in rendered output.
- AI agents: `InventoryExtractor::fake([['title' => ..., 'description' => ...]])`, `fake(fn () => throw ...)`, `assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2)`; `Exceptions::fake()` to assert `report()`. No automated test talks to Ollama; a live check is `Import::create([...]) + ExtractImport::dispatch()` in tinker with a JPEG under `storage/app/private/imports/`.
- Write a failing test before production code. Run Pint and PHPStan before committing.

## Laravel Boost

- Boost's MCP server must run inside Docker: `.mcp.json` uses `docker compose exec -T app php artisan boost:mcp`. `php artisan boost:install` overwrites it with a host command; restore with `git checkout .mcp.json`.
- Third-party skills (e.g. `filament-development`) are only discovered when the package is listed under `packages` in `boost.json`; refresh guidelines and skills with `php artisan boost:update`. The `<laravel-boost-guidelines>` block below is managed by Boost; do not hand-edit it.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
