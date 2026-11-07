# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Tory

Vocabulary lives in `CONTEXT.md`.

Laravel 13 + Filament 5 inventory app running on FrankenPHP (PHP 8.5) with Postgres 18, developed entirely in Docker. The Filament panel is the whole app and is served at `/`. Feature design docs and implementation plans live in `docs/superpowers/specs/` and `docs/superpowers/plans/` (one pair per feature, dated).

## Running the app

- `docker compose up -d` starts three services: `app` (FrankenPHP + queue worker + scheduler under supervisord, http://localhost:8000), `db` (Postgres 18 with the `pgvector` extension, image `pgvector/pgvector:pg18-trixie`, port 5432), `vite` (Vite dev server, http://localhost:5173, serves the panel's slideshow script).
- The project directory is bind-mounted into `app` and `vite` at `/app`. Edit files on the host; no rebuild needed.
- The app entrypoint (`docker/entrypoint.sh`) creates `.env`, generates `APP_KEY`, runs `composer install` when `vendor/` is missing, and runs migrations when `AUTO_MIGRATE=true` (set in `compose.yaml`).
- Rebuild the image after changing `docker/` (`docker compose build app && docker compose up -d app`); recreate the container after changing `compose.yaml` (`docker compose up -d app`). The `app` service has a 4-minute stop grace period so a running extraction job can finish; a hard kill leaves an import on "Läuft" until the queue's `retry_after` (300 s) fails it.
- **Production image:** the `prod` target of `docker/Dockerfile` contains the code and runs as `www-data` (ports 80/443 through a file capability). It refuses to start without `APP_KEY`, has no `.env`, logs to stderr and needs `DB_*`, `APP_URL`, optionally `AUTO_MIGRATE=true`, and a volume or bind mount on `/app/storage` for the photos (owned by uid 33; the entrypoint creates the directories inside). `AUTH_SELF_SERVICE=false` is set in the image, which removes registration and password reset from the panel (config `auth.self_service`, on by default elsewhere). Behind a TLS-terminating proxy set `TRUSTED_PROXIES` (comma-separated IPs or ranges), otherwise URLs come out as `http`. Releases are built for arm64 only, on a native arm64 runner, because the server is arm64. The database needs the `vector` extension. Pushing a tag like `1.0.0` runs `.github/workflows/release.yml`, which pushes `tory:{tag}` and `tory:latest` to `registry.digitalocean.com/$DIGITALOCEAN_REGISTRY` (repository secrets `DIGITALOCEAN_ACCESS_TOKEN`, `DIGITALOCEAN_REGISTRY`). The workflow does not wait for the tests.

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

- **Panel:** configured in `app/Providers/Filament/AdminPanelProvider.php` (id `admin`, path `''`). Everything under `app/Filament/` is auto-discovered. `routes/web.php` must never define `/`. The REST API lives in `routes/api.php` (see REST API). Auth is Filament's (login, registration, password reset, email verification, profile); `App\Models\User` implements `FilamentUser` (every user may access the panel) and `MustVerifyEmail`. There is no policy layer: every verified user sees every record (single household).
- **Resource layout:** a resource is a directory, e.g. `app/Filament/Resources/Imports/` with `ImportResource.php`, `Schemas/ImportForm.php` (create form), `Schemas/ImportInfolist.php` (view page), `Tables/ImportsTable.php` and `Pages/{List,Create,View}Import.php`. Status enums in `app/Enums/` implement Filament's `HasLabel`/`HasColor` so a `TextEntry`/`TextColumn` `->badge()` renders German labels and colours directly. UI copy is German: the locale defaults to `de` (`config/app.php`, the production image sets no `APP_LOCALE`), Filament brings its own German texts, Laravel's messages live in `lang/de/` by hand, and dates show as `d.m.Y H:i` (`AppServiceProvider`). Resource pages use the traits in `app/Filament/Resources/Concerns/`: every breadcrumb starts with a house linking to the dashboard (`StartsBreadcrumbsAtHome`; the dashboard itself has none), a view page is titled by the record alone, and view and list pages do not end their breadcrumb on a page label (edit and create pages keep theirs).
- **Dashboard:** `App\Filament\Pages\Dashboard` ("Übersicht", action "Erfassen") holds the widgets in `app/Filament/Widgets/`, none of them lazy: `ItemSearchWidget` (a plain GET form to the item list's `search` parameter), `RecentItemsWidget` (the latest 8 items in the shared item grid, full width, at the bottom), and above it side by side `OpenImportsWidget` (every import row is open, since conversion deletes it; hidden with none) and `LocationsWidget` (items per location, each linking to the item list's filter). `getWidgetsContentComponent()` lays out search, then imports and locations in one row (a single visible one fills it), then the recent items. Its grid and side column are styled by `resources/css/item-grid.css`.
- **Item grid:** every list of items (dashboard, `/items`, the items on a spot's page) is built by `App\Filament\Schemas\ItemGrid::configure()` as photo tiles with no table view, page sizes 12, 24 and 48. Every tile shows where the item lies (code chip and `{location name} · {name}`, both linking to the spot), on a spot's page too, so tiles look the same everywhere. `/items` keeps a visually hidden `created_at` column so the sort menu can offer it.
- **REST API:** JSON under `/api/v1` for a mobile client, a second door to the panel's rules (routes `routes/api.php`, names `api.v1.*`; controllers `app/Http/Controllers/Api/V1/`, Form Requests `app/Http/Requests/Api/V1/`, Resources `app/Http/Resources/Api/V1/`). Auth is Sanctum API tokens only (no SPA mode): `POST /login` (`email`, `password`, `device_name`; 5/min per IP; 403 for an unverified user, no token) returns a plain text token named after the device, tokens never expire, `personal_access_tokens` uses `uuidMorphs('tokenable')` because users have UUIDs, and everything else sits behind `auth:sanctum` + `EnsureEmailIsVerified` (German 403; `ForceJsonResponse` makes the whole `api` group expect JSON, so a missing `Accept` header still answers 401/403). Spots appear only by `code` (any case, a non-code answers 404 without a query; `Spot::normalizeCode()`/`resolveRouteBinding()`), the bigint id never leaves the server; the user's `last_spot` is a code too. Imports, items and locations use UUIDs. Imports and items are paginated by 24; items take `search` (`ItemSearch`), `location` and `spot` filters. Photos are served only through `GET /{imports|items}/{id}/photos/{file name}` (basename of the stored path, bearer token, 404 if not among the record's photos; `PhotoUrls`), never as signed URLs. Rules shared with the panel: upload limits as in "Erfassen", `Import::restart()` for retry (also the panel's action), convert and delete answer 409 in the wrong state (`Import::isFinished()`), item update answers 409 while regenerating, a second convert answers 404. Docs: `dedoc/scramble` serves OpenAPI at `/docs/api` and `/docs/api.json` (`config/scramble.php`, `api_path` `api/v1`), gated by `Gate viewApiDocs` (verified panel session; everyone else 403, except Scramble's own bypass in `local`); the bearer scheme is declared in `AppServiceProvider`, `login` carries `@unauthenticated`.
- **Import pipeline (the core feature):** "Importe" → "Erfassen" (`/imports/create`) uploads 1–6 photos to the private `local` disk under `imports/`, creates an `App\Models\Import` as `pending` and dispatches `app/Jobs/ExtractImport.php`. The job marks the import `running`, sends the photos to the structured-output agent `app/Ai/Agents/InventoryExtractor.php`, and stores a German `title`, `description` and `search_terms` (or, via `failed()`, the `error`). The view page (`/imports/{id}`) polls with `wire:poll.5s` while the import is pending/running, has a Retry action on failed imports, and shows the raw error only when `app.debug` is on (a failure the user can fix on "Eigene KI" shows a fixed German sentence with a link instead, see AI providers). A completed or failed import is turned into an item via "Übernehmen" on its view page; "Löschen" removes an import and its photos.
- **Spots:** every `Spot` ("Lagerplatz") gets a random `code` on creation: 4 characters from digits and uppercase letters without `0`, `O`, `1`, `I` (`CODE_ALPHABET`, about 1 million codes; unique index). It is what users see (`label` accessor: `{code} · {name}`) and the route key; the bigint `id` stays internal and carries the foreign keys. Neither code nor address may ever change (`code` is not fillable). `/spots/{code}` is the QR target of the labels: its view page lists the items inside (relation manager). The resource accepts the code in any case; anything else answers 404. The shared `SpotSelect` picks one; `users.last_spot_id` preselects the user's last choice on the import form and in "Übernehmen". Items have a NOT NULL spot (restrict on delete). A spot belongs to a `Location` (`location_id`); `full_label` is `{location name} → {code} · {name}` and is what the imports list and the view pages of items and imports show (item tiles show the code as a chip, then `{location name} · {name}`) (the spot's own list and page show code and name plus a Lagerort field). The `SpotSelect` search runs server-side and matches spot code, spot name and location name; the picker shows `label` for each option, grouped under the location name. A Select without a relationship needs `->actionSchemaModel(Location::class)` when its create form has a `unique()` field (the spot form's inline Lagerort creation does).
- **Labels:** "Etikett erzeugen" on a spot's view page and "Etiketten erzeugen" as a bulk action in its list (`App\Filament\Actions\SpotLabelsAction`) ask for a `LabelSize` and download an A4 PDF that is not stored. The lowercase file name holds the size and the spot name for one spot, the location name for several spots of one location, otherwise `etiketten` (e.g. `keller-klein.pdf`). `SpotLabelSheet` renders `resources/views/pdf/spot-labels.blade.php` through `spatie/laravel-pdf` with WeasyPrint (in the `base` image stage with `fonts-dejavu-core`; `config/laravel-pdf.php` sets the driver and a 60 s timeout, the default of 10 s fails at several hundred labels). Labels are sorted by code and carry the QR code and, beside it, the code on top, the name right under it and the address without scheme at the bottom, but no location. A long name is cut with "…"; code and address stay complete. The QR code (`chillerlan/php-qrcode`, one `QRCode` instance per label because an instance keeps what it rendered) holds `APP_URL` plus `/spots/{code}`, so labels printed from a local install point to `localhost`. One test renders real PDFs, so the suite needs the `app` image.
- **Locations:** `Location` ("Lagerort") only groups spots: unique name, UUID key, no code and no detail page, managed at `/locations`, restrict on delete while spots exist; the backfill created "Allgemein" for pre-existing spots. The item list has a "Lagerort" filter that combines with the text search.
- **Items:** `Item` (title, description, `photos`, nullable `embedding` vector(1024)) is created only by `Import::convertToItem()` ("Übernehmen" on the import view page); there is no create page. Photo files belong to exactly one import or one item: conversion deletes the import row first with a query-builder delete (no model event, so the files are kept; they now belong to the item) and throws `ModelNotFoundException` when the row is already gone (a second "Übernehmen" answers 404 and creates nothing); "Übernehmen" also stores the chosen spot as the user's `last_spot_id`; any plain delete of an import or item removes its files. Thumbnails carry `data-slideshow` (all photo URLs, the same temporary signed links as the thumbnails, so a tab open past their expiry needs a reload) and `data-slideshow-index` from `App\Filament\Schemas\PhotoSlideshow`; a click opens them in GLightbox, in lists too, and elsewhere in a row opens the view page. "Fotos" on the edit page (`App\Filament\Forms\Components\ItemPhotos`, state `photo_edits`, Alpine component `itemPhotos` in `resources/js/item-photos.js`, styled by `resources/css/photo-picker.css`) adds, removes and chooses photos: a click chooses the overview photo, ✕ marks a photo until "rückgängig", and nothing is final until "Speichern". The browser rejects a file that is no JPEG, PNG, WebP or GIF or over 12 MB, shrinks the others to fit 2048 × 2048 px on a canvas (GIFs stay untouched, so they stay animated) and uploads them one at a time through Livewire; the counter store `toryPhotoUploads` disables "Speichern" meanwhile. The server checks type and size again. `Item::changePhotos()` applies the marks, uploads and overview choice to the row's current `photos` under a lock, so a stale tab cannot bring a photo back, keeps 1 to 6 photos (an old item without photos may stay without), puts the chosen photo first and moves new ones to `imports/`; files of removed photos are deleted only after the commit, and a failed deletion is reported without undoing the save. Lists, view page and slideshow follow the order of `photos` without further code. Delete exists only on view pages. Global search is disabled panel-wide (`record title` attributes only feed headings). Navigation order: Gegenstände, Lagerplätze, Lagerorte, Importe, Eigene KI.
- **Embeddings:** `App\Ai\ItemEmbedder` embeds for an `EmbeddingTarget` (`current()`: the provider active for search, otherwise `ai.inventory.embedding_provider`/`embedding_model`, env `INVENTORY_EMBEDDING_PROVIDER`/`INVENTORY_EMBEDDING_MODEL`, defaults `ollama`/`bge-m3`, as fallback only; `identity()` changes with the target; throws `WrongEmbeddingDimensions` unless the vector has 1024 dimensions; failures on a provider leave as `ProviderFailure`) is called by `App\Jobs\EmbedItem`, dispatched from `Item` model events when title, description or search terms change. Job and command share `ItemEmbedder::embedAndStore()`, which stores the vector (row-locked, no model events, `updated_at` untouched) only if the item's text is still the embedded text and the current target is still the one it embedded for (it locks the row before reading the target, so a switch either overwrites or is seen). The job uses a 45 s client timeout (job timeout 60). `items:embed {--all}` (scheduled hourly) catches up items without an embedding via `App\Ai\EmbeddingRunner` (shared with `ReembedItems`; stops early after 3 consecutive failures; stores the last failure reason in `ai_providers.search_failure` of the provider active for search, a clean run clears it); `--all` recomputes every item. Run `ollama pull bge-m3` once on the Ollama host (or pick another embedding model on a provider). Changing the dimension needs a migration of the `embedding` column plus `items:embed --all`. Needs the `vector` extension, so `db` is `pgvector/pgvector:pg18-trixie`.
- **Search terms:** `search_terms` on imports and items (`jsonb` array of plain strings, not null, default empty) are suggested by the extractor, shown as badges on view pages and edited as tags ("Suchbegriffe") in "Übernehmen" and on the item edit page. `InventoryExtractor::extract()` returns the DTO `ExtractedTexts` and cleans the terms; missing terms give an empty list and do not fail an import. Terms are part of keyword search (matched per array element, not on the JSON text) and of `ItemEmbedder::textFor()`; with empty terms that text is unchanged, so old embeddings stay valid. The prompt has all providers describe the thing rather than the photo or its packaging, name its kind even if unprinted, and never invent brand, model, serial number or capacity; judge changes to it with `extraction:eval`.
- **Neu generieren:** an action on the view page of items and completed imports (hidden without photos) dispatches `App\Jobs\RegenerateTexts`, which replaces title, description and search terms using the active provider or the `.env` fallback. State columns on both tables: `regenerating_at`, `regeneration_error`, `regeneration_failure_reason`, `regeneration_failure_provider` (trait `RegeneratesTexts`). While it runs, the page shows "Wird neu generiert …" and polls, and "Neu generieren", "Bearbeiten" and "Übernehmen" are hidden. On failure the old texts stay and the page shows the reason as for imports. There is no bulk variant and no backfill, because each press may cost money.
- **Search:** `App\Ai\ItemSearch::apply($query, $term)` (list search box): items whose title/description/search terms contain every word come first, then semantic matches with cosine similarity >= the target's `minSimilarity` (`ai_providers.search_min_similarity`, per provider because values differ between models; with no provider `ai.inventory.search_min_similarity`, env `INVENTORY_SEARCH_MIN_SIMILARITY`, default 0.5; tune if unrelated items show up or related ones are missing). Query vectors are cached 10 minutes through the SDK (`->cache(600)`, shared between providers with the same vendor and model); `extraction:eval` takes the same threshold. If embedding the query fails (3 s timeout) the error is reported and only the keyword match applies, so search works without Ollama/bge-m3.
- **Queue timing invariant:** job `$tries = 1`, `$timeout = 200`; supervisord runs the worker with `--timeout=210` (dev uses `queue:listen`, which kills the child at its own timeout regardless of the job's); the database queue `retry_after` defaults to 300 (`DB_QUEUE_RETRY_AFTER`). `RegenerateTexts` also has `$tries = 1`, `$timeout = 200`. `ReembedItems` also has `$tries = 1`, `$timeout = 200` (it works for 150 s, then dispatches itself with a cursor). `EmbedItem` has `$tries = 3`, `$timeout = 60`. Keep every job timeout < 210 < 300 when changing any of them. Dispatch happens inside Filament's create transaction.
- **AI providers ("Eigene KI"):** `App\Models\AiProvider` (vendor enum `AiVendor`: Ollama, Claude/`anthropic`, OpenAI, Mistral; name unique; `model` (object recognition) and `embedding_model` (search; not for Claude; at least one of the two; `search_min_similarity` 0 to 1 with it); `url` for Ollama only) is managed on `/ai-providers` (`AiProviders` resource, one `ManageAiProviders` page with the providers as cards, create/edit in modals). At most one is active for object recognition (partial unique index `ai_providers_one_active`; `activate()` locks all rows and clears the old one first) and at most one for search (`is_active_for_search`, own index); the two may differ. Search is switched by `App\Ai\SearchProviderSwitch` ("Für Suche aktivieren" or a changed embedding model on the active one): `probe()` embeds a short text and needs exactly 1024 dimensions (it runs when the dialog would open and again on confirmation, with the record's current values; on the edit dialog the confirmation is the required checkbox `confirm_search_switch` and the probe uses the submitted values, so a failure saves nothing), `switchTo()` in one transaction sets the flag and model, clears `search_failure` and every embedding (query builder) and dispatches `ReembedItems` after commit. The card then shows "Suche wird neu aufgebaut" with a 5 s poll while items lack an embedding. Key, Ollama address, name, `model` and minimum similarity are not a switch. A new provider is never activated automatically, and nothing ever switches or fails over on its own. A provider active for either role cannot be deleted (model `deleting` hook, disabled action), so the UI has no way back to the `.env` fallback. `api_key` is `encrypted` with `APP_KEY` (changing it makes keys unreadable; the card shows a warning and imports/tests report `KeyUnreadable`), hidden from serialisation, never filled into the edit form (empty field keeps the key); the card shows `key_hint` (last 4 characters, saved with the key, so rendering never decrypts). `ProviderModels::for()` fetches model suggestions (Ollama `/api/tags`, others `/models`; 5 s timeout, cached 60 s; `null` on failure, free text always works). `ProviderTester` ("Testen") runs the real extractor on `resources/ai/test-image.png` with a 120 s timeout and probes the embedding model, each only if configured, and returns a German reason (naming both roles when both are set) or null. `ProviderFailure` wraps any exception of a job that ran on an `AiProvider`: the message is scrubbed of the key, the original exception is not kept as `previous`, and `ProviderFailureReason::fromThrowable()` classifies it (key rejected, key unreadable, unknown model, images unsupported, unreachable; `null` otherwise). `ExtractImport::failed()` stores `failure_reason`/`failure_provider` on the import; the view page then shows e.g. "Claude hat den API-Key abgelehnt. Provider prüfen" linking to "Eigene KI" even with `app.debug` off, and the general message for unclassified failures. `ProviderTransfer` moves providers between installations as JSON with plain text keys ("Exportieren"/"Importieren" on the page, because stored keys depend on `APP_KEY`): `export()` returns a `ProviderExport` of `ProviderTransferEntry` items and `import()` a `ProviderImportResult`; a provider is identified by its UUID key (the file format calls it `uuid`), an existing uuid is skipped without change, a new uuid with a taken name becomes "Name (Import)", a file is imported completely or not at all, and neither direction carries an active state (the file also holds `embedding_model` and `search_min_similarity`, `model` may be null, old files stay importable, an import never starts a recomputation); the page deletes the upload afterwards. Risk wherever `AUTH_SELF_SERVICE` is on (the default outside the production image): any verified registrant could download all stored keys in plain text, spend them, change the active provider and trigger a search switch (which recomputes every item).
- **AI:** `laravel/ai` with Anthropic as the app default (`config/ai.php`, `ANTHROPIC_API_KEY`). The extractor runs on `AiProvider::active()`, read fresh in every `ExtractImport` run (the worker is long-lived); only with no active provider does it read its own provider/model from `config('ai.inventory.*')` (`INVENTORY_AI_PROVIDER`, `INVENTORY_AI_MODEL`) as before. Embeddings follow the provider active for search the same way, with `EmbeddingTarget` falling back to `config('ai.inventory.embedding_*')`; nothing fails over from a provider to `.env`, since that would mix models. For the `.env` fallback with local Ollama set `INVENTORY_AI_PROVIDER=ollama`, `INVENTORY_AI_MODEL=gemma4:e4b-mlx`, `OLLAMA_URL=http://host.docker.internal:11434`. The extractor's instructions ask for a bare JSON object because `gemma4` ignores Ollama's `format` schema and otherwise prefixes `json`, which fails decoding. `app/Ai/Agents/Assistant.php` is an unused stub.
- **Theme:** `users.theme_mode` (nullable, Filament's `ThemeMode`) holds the choice from the user menu's switcher. `resources/views/filament/theme-mode.blade.php` (`HEAD_START` render hook, logged-in users only) writes it to `filament:theme:mode` in `localStorage` before Filament reads that key, so it applies without a flash, and posts a changed choice to `POST /theme` (`SaveThemeMode`). The switcher fires `theme-changed` on every page load too, so the script only saves a value that differs from the one the page started with. With no saved theme the browser's value stays and nothing is saved until the user picks another; the login page has no account and keeps the browser's value.
- **Assets/styling:** Filament's precompiled theme; generated assets in `public/{css,js,fonts}/filament` are gitignored and refreshed by `filament:upgrade` on `composer dump-autoload` (run `php artisan filament:assets` after a manual upgrade). The panel loads `resources/js/slideshow.js` and `resources/js/item-photos.js` and `resources/css/item-grid.css` (with `resources/css/slideshow.css` and `resources/css/photo-picker.css`, plain CSS because uncompiled Tailwind classes do not exist in the panel) through a `HEAD_END` render hook with `@vite`, so panel pages outside tests need the running `vite` service or a build. `resources/css/app.css` and `resources/js/app.js` are entries nothing loads yet (Tailwind 4 in the CSS); add a theme with `php artisan make:filament-theme admin` when needed. No app-level Blade layout exists, so a `Route::livewire()` full-page component would fail until `php artisan livewire:layout` creates one. Livewire's `make_command.emoji` is off.

## Tickets and branches

- No code change without a ticket. Tickets live in Kaneo (`TOR-<number>`).
- Before creating a ticket, always ask the user first.
- Work on a ticket happens in its own branch named `feature/TOR-<number>-<short-description>`, e.g. `feature/TOR-4-user-theme`.

## Code conventions

- **YAGNI:** build only what is needed now; no speculative options, abstractions or extension points.
- **Standard libraries over custom code:** prefer what Laravel, Filament or an established library already provides to building it ourselves. A new dependency still needs approval.
- **Primary keys:** tables use UUIDs (`HasUuids`, version 7) unless explicitly specified otherwise. `Spot` is such an exception (internal bigint `id` plus the visible `code`); framework tables such as `jobs` stay as they are. `HasUuids` answers 404 for a route key that is no UUID.
- **DTOs over arrays:** pass and return structured data as DTOs, not arrays. Arrays remain where a framework API demands them (e.g. Filament schemas, `$fillable`, casts).
- **Constructor injection:** inject dependencies through the constructor instead of calling `resolve()` or `app()` in the code. Where the framework rules out a constructor, use its own injection: typed parameters on Filament closures (`->action(fn (ProviderTransfer $transfer) => ...)`), `$this->app` in service providers. Logic that needs a service does not belong on a model; move it to a class of its own (`ItemSearch`).

## Testing conventions

- Pest on the Postgres database `tory_testing` (`phpunit.xml`; created by `docker/postgres/init/` on a fresh volume, otherwise once with `docker compose exec db createdb -U tory tory_testing`). `TestCase::guardTestingDatabase()` runs on every app boot and refuses any other database, so the suite can never refresh `tory`. Postgres sequences are not reset between tests: never assert a literal id; `RefreshDatabase` is applied to every Feature test in `tests/Pest.php`.
- Panel pages: `Livewire::test(PageClass::class, ['record' => $id])->fillForm([...])->call('create')`, `->callAction('retry')`, `->assertActionHidden(...)`, `->assertCanSeeTableRecords([...], inOrder: true)`. Use `Storage::fake('local')` for uploads and `Queue::fake()` unless the job itself is under test. `TestCase::setUp()` calls `Embeddings::fake()`, so no test reaches a real embedding provider; override it with `Embeddings::fake([...])` or a throwing closure to test `ItemEmbedder`, `EmbedItem` and search.
- AI providers: with an `AiProvider::factory()->active()` in a job test, failures are wrapped in `ProviderFailure` (scrubbed message, no `previous`), so assert on `failure_reason`/`failure_provider` rather than the raw exception; with none, the `.env` path and raw errors apply. Embedding failures on a provider are wrapped in `ProviderFailure` too. Model lists and vendor errors are tested with `Http::fake()`; never assert a key in rendered output.
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
