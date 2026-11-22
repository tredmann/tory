# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Tory

A household inventory that answers one question: where is that thing? Vocabulary (German UI word and English code name) lives in `CONTEXT.md`; every feature change checks it against the new behaviour and updates it in the same branch.

Laravel 13 + Filament 5 on FrankenPHP (PHP 8.5) with Postgres 18 and pgvector, developed entirely in Docker. The Filament panel is the whole app and is served at `/`; the REST API under `/api/v1` is a second door to the same rules. Each feature has a dated design doc in `docs/superpowers/specs/` and plan in `docs/superpowers/plans/`: read the feature's spec before changing it.

## Running the app

- `docker compose up -d` starts `app` (FrankenPHP + queue worker + two thumbnail workers + scheduler under supervisord, http://localhost:8000), `db` (`pgvector/pgvector:pg18-trixie`, port 5432) and `vite` (http://localhost:5173). The project is bind-mounted at `/app`; edit on the host.
- The entrypoint (`docker/entrypoint.sh`) creates `.env` and `APP_KEY`, runs `composer install` when `vendor/` is missing and migrates when `AUTO_MIGRATE=true`. Tests migrate `tory_testing` only, so after adding a migration run `php artisan migrate` on the dev database too.
- After changing `docker/`: `docker compose build app && docker compose up -d app`. After changing `compose.yaml`: `docker compose up -d app`.
- `app` has a 4-minute stop grace period so a running extraction can finish; a hard kill leaves an import on "Läuft" until the queue's `retry_after` (300 s) fails it.
- Logs: `docker compose logs -f app`; processes: `docker compose exec app supervisorctl status`. Mail uses the `log` driver; verification links land in `storage/logs/laravel.log`.

## Production and releases

- The `prod` target of `docker/Dockerfile` runs as `www-data`, refuses to start without `APP_KEY`, has no `.env`, logs to stderr and needs `DB_*`, `APP_URL`, optionally `AUTO_MIGRATE=true`, and a volume on `/app/storage` (uid 33). There is no registration or password reset. A fresh install (no user at all) sends every panel page to `/setup`, where the first visitor becomes administrator. The CLI path stays and closes that page: `users:make-admin <email>` promotes an existing user, created first with `make:filament-user` and marked verified (the CLI user is unverified and no reset exists). Behind a TLS-terminating proxy set `TRUSTED_PROXIES`, otherwise URLs come out as `http`. The database needs the `vector` extension. `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` (PEM contents, from `passport:keys`) are required for the MCP server and the image refuses to start without them; dev and `composer setup` generate keys into `storage/` (gitignored).
- Pushing a tag like `1.0.0` runs `.github/workflows/release.yml`: one build per platform (amd64 and arm64, each on a native runner, the server is arm64) pushed to Docker Hub as `tredmann/tory:{tag}-{arch}`, then joined into the public multi-platform tags `tredmann/tory:{tag}` and `tredmann/tory:latest` (secrets `DOCKERHUB_USERNAME`, `DOCKERHUB_TOKEN`). It does not wait for the tests. The tag goes in as build argument `APP_VERSION` and shows in the user menu (`config('app.version')`, "dev" otherwise).
- Where the server runs and how to deploy a release to it: `docs/private/deploy.md` (gitignored, local only).
- Every release tag is annotated (`git tag -a <version> -F <notes file>`, then `git push origin master <version>`); earlier tags were removed in TOR-26, so `1.0.0` is the first. Its message is the release notes: a Markdown bullet list in English with plain text in each bullet, saying briefly what changed for the people using Tory, not for developers. One or two bullets per feature, five at most. Show the draft to the user before tagging.

## Running commands

Everything PHP runs in the `app` container, everything npm in the `vite` container; never use host PHP/Composer/npm.

```sh
docker compose exec app php artisan <command>
docker compose exec app composer <command>
docker compose exec app php artisan test --compact [tests/Feature/Panel/ViewImportTest.php] [--filter=retry]
docker compose exec app ./vendor/bin/pint --dirty
docker compose exec app ./vendor/bin/rector process [--dry-run]
docker compose exec app ./vendor/bin/psalm
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app php artisan make:filament-user --name=Admin --email=admin@example.com --password=secret
docker compose exec app php artisan users:make-admin admin@example.com
# CLI-created users are unverified:
docker compose exec app php artisan tinker --execute='App\Models\User::firstWhere("email", "admin@example.com")->markEmailAsVerified();'
docker compose exec app php artisan queue:failed
docker compose exec app php artisan extraction:eval --provider="Ollama lokal" --runs=3 --language=de
docker compose exec vite npm <command>
docker compose exec db psql -U tory -d tory
```

`extraction:eval` runs the real extractor on `tests/Evals/extraction/` (not part of the suite), writes nothing to the database and saves its report under `storage/app/private/evals/`. It calls the named provider for real, so a paid provider costs money per run. `--language=de|en` picks the prompt and the cases' expected language. Judge every change to the extraction prompt with it, once per language.

## Architecture map

- **Panel:** `app/Providers/Filament/AdminPanelProvider.php` (id `admin`, path `''`); everything under `app/Filament/` is auto-discovered. Filament's own auth; `User` implements `FilamentUser` and `MustVerifyEmail`. Two roles (`UserRole`); `UserPolicy` and `AiProviderPolicy` make the user list and "Eigene KI" admin-only; everything else has no policy and is open to every verified, unlocked user (one household). Global search is off.
- **Resources:** one directory per resource (`ImportResource.php`, `Schemas/`, `Tables/`, `Pages/`); breadcrumb and title traits in `app/Filament/Resources/Concerns/`. Status enums in `app/Enums/` implement `HasLabel`/`HasColor` for badges.
- **Languages** (spec german-english): *interface language* per user (`users.locale`, null = follow the browser, switched in the user menu); *content language* per household (`HouseholdSettings`, single row, page „Haushalt“ = `HouseholdSettingsPage`, admin-only, under „Einstellungen“). The content language picks the `InventoryExtractor` prompt (a German and an English one, German frozen by a snapshot test).
- **Locale resolution:** `SetLocale` takes the user's `locale`, else the browser for a user without a choice, else the household content language for guests, else the browser while no user exists. It runs in the panel, the `web` group (OAuth consent, Livewire updates), the API after `auth:sanctum` and `/mcp` after `auth:api`. MCP instructions and tool descriptions are fixed English; answers follow the token owner's locale.
- **Translations:** keys are English text, German lives in `lang/de.json` (Laravel's own messages in `lang/de/` by hand). `TranslationsTest` finds every key used in code, including `trans_choice` (PHP-file keys like `auth.failed` are skipped); `EnglishInterfaceTest` checks the English panel for German leftovers. Dates: `Language::dateTimeFormat()` (`app/Enums/Language.php`, consumed by `AppServiceProvider`). `ai_providers.search_failure_*` hold codes turned into text at render time, not translated strings.
- **Dashboard and item grid:** `App\Filament\Pages\Dashboard` with widgets in `app/Filament/Widgets/`; every list of items is `App\Filament\Schemas\ItemGrid` photo tiles and looks the same everywhere. A tile shows up to three photos (spec item-grid-gallery): hover strips, swiping and dots from `resources/js/item-gallery.js` and `resources/css/item-grid.css`.
- **Onboarding** (spec onboarding): `App\Onboarding\Onboarding`, `OnboardingWidget`, `household_settings.content_language_confirmed_at` / `onboarding_finished_at`.
- **Imports** (specs inventory-extraction, async-import): "Erfassen" creates a pending `Import`, `ExtractImport` runs `App\Ai\Agents\InventoryExtractor`, "Übernehmen" turns it into an item through `Import::convertToItem()`.
- **Bulk import** (spec bulk-import): `BulkImport`, grouped by `GroupBulkImport`; the review page edits through locked model methods that throw `StaleBulkImport`; confirming creates one import per group.
- **Spots, locations, labels** (spec spots-and-locations): `Spot` ("Lagerplatz", visible `code`, internal bigint id) belongs to a `Location` ("Lagerort"); `/spots/{code}` is the QR target. Labels: `SpotLabelsAction` and `SpotLabelSheet` render `resources/views/pdf/spot-labels.blade.php` with `spatie/laravel-pdf` and WeasyPrint.
- **Items and photos** (specs item-photo-editing, photo-slideshow, photo-endpoint, background-thumbnails): `Item::changePhotos()`, `app/Photos/` (`PhotoStore`, `Thumbnails`, `PhotoResponder`, `PhotoLinks`), the job `GenerateThumbnail`, behind the panel routes `/photos/…` and `/photos/thumb/…`, the `ItemPhotos` component with `resources/js/item-photos.js`, GLightbox through `PhotoSlideshow`.
- **Search and embeddings** (specs items-locations-search, extraction-search-terms, selectable-embedding-provider): `App\Ai\ItemSearch` (keyword matches first, then cosine similarity >= the target's `minSimilarity`; keyword-only if embedding the query fails), `ItemEmbedder`, `EmbeddingTarget`, `EmbedItem`, `items:embed` (hourly).
- **Neu generieren:** `RegenerateTexts` with the `RegeneratesTexts` trait on imports and items.
- **AI providers** ("Eigene KI", specs ai-provider-settings, selectable-embedding-provider): `AiProvider` on `/ai-providers`, one active for object recognition and one for search (may differ); `SearchProviderSwitch`, `ProviderTester`, `ProviderFailure`, `ProviderTransfer`. There is no `.env` fallback: without an active provider image recognition fails and search is keyword-only. Local development creates an Ollama provider (`gemma4:e4b-mlx`, URL `http://host.docker.internal:11434`) plus one with `bge-m3` for search under "Eigene KI".
- **REST API** (spec rest-api, `docs/api-handover.md`): `routes/api.php`, controllers, Form Requests and Resources under `Api/V1/`; Sanctum tokens only; OpenAPI by `dedoc/scramble` at `/docs/api`, gated by `Gate viewApiDocs`.
- **MCP server** (spec mcp-server, "Tory in Claude"): `routes/ai.php` registers `/mcp` (`ToryServer`, tools in `app/Mcp/Tools/`) behind Passport `auth:api` and `EnsureUserIsActive` (verified and not locked), plus `Mcp::oauthRoutes()`. `ClaudeConnections` lists and ends connections, `TorySetup` is the panel page (`resources/css/tory-setup.css`, a direct Vite entry in the `HEAD_END` hook). Tools reuse the rules of the panel and API.
- **Users** (specs user-management, first-administrator-setup): `UserResource` on `/users`, invite page `App\Filament\Auth\AcceptInvite` on `/invite/{token}`, `LogOutLockedUser` (web and panel) and `EnsureUserIsActive` (API, `/mcp`). While `users` is empty, `RedirectToFirstAdministratorSetup` sends every panel request to `SetUpFirstAdministrator` on `/setup` (404 once any user exists), which calls `User::createFirstAdministrator()` under an advisory lock.
- **Theme:** `users.theme_mode`, written to `localStorage` by `resources/views/filament/theme-mode.blade.php` before Filament reads it, saved through `POST /theme`.
- **Assets:** Filament's precompiled theme. A `HEAD_END` render hook loads the panel's JS and CSS from `resources/js/` and `resources/css/` with `@vite`; they are plain CSS because uncompiled Tailwind classes do not exist in the panel. `resources/css/app.css` and `resources/js/app.js` are unused entries.

## Invariants

- **Photo files** belong to exactly one bulk import, import or item. Handing them on (bulk import to imports, import to item) deletes the old row with a query-builder delete, so no model event removes the files; every plain delete removes its files. Files are stored and deleted only through `PhotoStore`; a thumbnail at `thumbs/…` belongs to its original's path. A second "Übernehmen" finds the row gone and answers 404.
- **Spot code and address never change.** `code` is 4 characters without `0`, `O`, `1`, `I`, is not fillable and is the only spot identifier that leaves the server (routes, API, labels).
- **Queue timing:** every job timeout < 210 (worker `--timeout`; dev's `queue:listen` kills the child at its own timeout) < 300 (`retry_after`). `ExtractImport`, `RegenerateTexts`, `ReembedItems` and `GroupBulkImport` use `$tries = 1`, `$timeout = 200` (the last two work 150 s, then dispatch themselves with a cursor); `EmbedItem` uses 3 and 60; `GenerateThumbnail` uses 1 and 60 on queue `thumbnails` (two workers of their own, `--timeout=90`). Dispatch happens inside Filament's create transaction.
- **Embeddings have 1024 dimensions** (`WrongEmbeddingDimensions` otherwise). Changing it needs a migration of `items.embedding` plus `items:embed --all`.
- **Nothing switches AI providers on its own:** no failover between providers, since that would mix models; an active provider cannot be deleted.
- **Provider keys** are encrypted with `APP_KEY`, so changing it makes them unreadable (`KeyUnreadable`). Never render, log or assert a key; `ProviderFailure` scrubs it. The export file holds plain keys.
- **Upload limits** are the same everywhere: 1 to 20 photos (`Import::MAX_PHOTOS`), JPEG/PNG/WebP/GIF, 12 MB each; in the API also 70 MB in total (`ValidatesPhotoUploads`).
- **Each AI call may cost money:** "Neu generieren" has no bulk variant and no backfill.
- **MCP connections:** dynamic client registration is open on purpose, so the consent page names the client by registered name and redirect host. Tokens last 100 years; a connection ends only by "Trennen", which revokes that user's access and refresh tokens for that client (`connectedAt` = oldest token).

## Gotchas

- `routes/web.php` must never define `/`.
- Navigation group labels must be closures: `panel()` runs before `SetLocale`, so a plain `__()` there is fixed to the default locale.
- `Livewire::test` skips middleware, so an English Livewire test sets the locale itself (`app()->setLocale('en')`). `TestCase` sends `Accept-Language: de` and `UserFactory` sets `locale` German, so every other test sees the German UI; an English test overrides both.
- `User` keeps only Sanctum's `HasApiTokens` and does not implement Passport's `OAuthenticatable` (the traits clash); it defines `oauthApps()` and `oauthTokens()` itself. Never use `$user->tokens()` for Passport tokens. A Passport upgrade that adds a runtime `instanceof OAuthenticatable` check would break MCP auth.
- `/mcp` is exempt from the guest redirect to the panel login (it must answer 401 with `WWW-Authenticate`); `/oauth/authorize` redirects guests to the panel login. MCP tests get tokens with `issueMcpTokens()` (real OAuth flow); a test with several requests must reset guards, as that helper does, because the app instance persists.
- `php artisan make:enum` writes to `app/`; move the file to `app/Enums/`.
- A Select without a relationship needs `->actionSchemaModel(...)` when its create form has a `unique()` field.
- `gemma4` ignores Ollama's `format` schema and prefixes `json`, so the extractor's instructions ask for a bare JSON object.
- One `QRCode` instance per label, because an instance keeps what it rendered. The QR holds `APP_URL`, so labels printed locally point to `localhost`. `config/laravel-pdf.php` sets a 60 s timeout; the default 10 s fails at several hundred labels. One test renders real PDFs, so the suite needs WeasyPrint (the `app` image).
- Panel pages outside tests need the running `vite` service or a build.
- Panel photos come from session-protected `/photos/…` routes; thumbnails are made by `GenerateThumbnail` when photos are stored and when a request finds one missing; meanwhile the thumbnail routes (panel and API) answer `resources/images/thumbnail-placeholder.png` (`no-cache`), never the original. A photo that cannot be thumbnailed is remembered in the cache (`thumbnail-failed:<path>`) and keeps the placeholder until the cache is cleared. The photo routes run the panel's session middleware, so every image request reads and writes the database session (a flash notification can rarely be lost while images load, and `url()->previous()` can point to a photo). The API never uses signed URLs; photos go through bearer-protected `/photos/{file name}` routes (thumbnails under `/thumb`).
- The browser reads EXIF capture time before the canvas resize drops it. `config/livewire.php` raises the upload throttle to 150/min because each photo is one request.
- No app-level Blade layout exists: a `Route::livewire()` full-page component fails until `php artisan livewire:layout`.
- Generated Filament assets in `public/{css,js,fonts}/filament` are gitignored and refreshed on `composer dump-autoload`; run `php artisan filament:assets` after a manual upgrade.
- The invite token is shown only once (only its hash is stored); a lost link needs "Neuer Link".

## Tickets and branches

- No code change without a ticket. Tickets live in Kaneo (`TOR-<number>`).
- Before creating a ticket, always ask the user first.
- Work on a ticket happens in its own branch named `feature/TOR-<number>-<short-description>`, e.g. `feature/TOR-4-user-theme`.

## Code conventions

- **YAGNI:** build only what is needed now; no speculative options, abstractions or extension points.
- **Standard libraries over custom code:** prefer what Laravel, Filament or an established library already provides. A new dependency still needs approval.
- **Primary keys:** UUIDs (`HasUuids`, version 7) unless specified otherwise. `Spot` is the exception (bigint `id` plus `code`); framework tables stay as they are. `HasUuids` answers 404 for a route key that is no UUID.
- **DTOs over arrays:** pass and return structured data as DTOs. Arrays remain where a framework API demands them (Filament schemas, `#[Fillable]`, casts).
- **Constructor injection** instead of `resolve()` or `app()`. Where the framework rules out a constructor, use its injection: typed parameters on Filament closures (`->action(fn (ProviderTransfer $transfer) => ...)`), `$this->app` in service providers. Logic that needs a service goes in a class of its own (`ItemSearch`), not on a model.

## Testing conventions

- Pest on the Postgres database `tory_testing` (created by `docker/postgres/init/` on a fresh volume, otherwise `docker compose exec db createdb -U tory tory_testing`). `TestCase::guardTestingDatabase()` refuses any other database. Sequences are not reset between tests: never assert a literal id. `RefreshDatabase` applies to every Feature test.
- A panel test that requests a guest page (`/login`, `/invite/…`, `/theme` as guest) needs a user in the database first; on an empty database every panel request redirects to `/setup`.
- Use `Storage::fake('local')` for uploads and `Queue::fake()` unless the job itself is under test. `TestCase::setUp()` calls `Embeddings::fake()`; override it to test `ItemEmbedder`, `EmbedItem` and search.
- With `AiProvider::factory()->active()` in a job test, failures arrive wrapped in `ProviderFailure` (scrubbed, no `previous`): assert on `failure_reason`/`failure_provider`. Vendor calls are tested with `Http::fake()`, the extractor with `InventoryExtractor::fake(...)`. No automated test talks to Ollama.
- Write a failing test before production code. Run Rector, Pint and PHPStan before committing (Rector first, Pint tidies its output). `composer test` checks all three plus Psalm, as CI does.
- **Psalm does taint analysis only** (`psalm.xml`, `runTaintAnalysis`; Psalm 6 with `psalm/plugin-laravel` 3.x, because Psalm 7 always adds type checks). It fails on user input reaching SQL, HTML, shell, headers or files. Fix a finding; only a proven false positive gets `@psalm-suppress Tainted…` with the reason at that line, never a baseline.

## Laravel Boost

- Boost's MCP server must run inside Docker: `.mcp.json` uses `docker compose exec -T app php artisan boost:mcp`. `php artisan boost:install` overwrites it with a host command; restore with `git checkout .mcp.json`.
- Third-party skills (e.g. `filament-development`) are only discovered when the package is listed under `packages` in `boost.json`; refresh with `php artisan boost:update`. The `<laravel-boost-guidelines>` block below is managed by Boost; do not hand-edit it.

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
