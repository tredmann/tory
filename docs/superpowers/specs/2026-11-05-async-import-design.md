# Asynchronous photo import — design

Date: 2026-11-05. Status: draft for approval. Builds on `2026-11-04-inventory-extraction-design.md`.

## Intent

Today "Erfassen" runs the AI extraction inside the web request: the user waits up to three minutes with a spinner, and nothing is stored. This round moves the extraction into a queue job. The upload creates an **import** record, stores the photos permanently, dispatches the job and redirects the user to the import's page, which shows the status and, once the job is done, the title and description.

An import is not yet an inventory item. The idea (user's words): an import is "an import job which could after extraction and maybe editing become an inventory item". Editing and converting are later rounds; this round is "only the async part and status page".

## Goals

- Upload one or more photos, get redirected immediately, no long-running request.
- Watch the import's status change on its page without pressing reload.
- See the result (German title and description) on that page when the job finishes.
- See why an import failed, and retry it.
- Find imports again later (a list).

## Non-goals (for now)

- Editing title or description, converting an import into an inventory item, an `Item` model, search.
- Deleting imports or photos.
- Per-user visibility rules: every verified user of this app sees every import (one household).
- Real-time push (websockets/broadcasting); polling is enough.
- Any change to the extractor's instructions, schema, provider or model config.

## Anti-goals

- An import stuck in "running" forever after a crash, with no way out. Every failure path must end in "failed" with a message, or the retry button must get it moving again.
- Photos reachable without being logged in.
- The queue re-running an extraction while the first run is still going.
- Losing the already-working synchronous behaviour's guarantees: German output, nothing invented, a clear notification-style failure. The job keeps using the existing `InventoryExtractor` unchanged.

## Constraints

- Stack as before: Laravel 13, Filament 5 panel at `/`, Livewire 4, `laravel/ai` 1.3, Pest, Pint, Larastan. Everything runs via `docker compose exec app ...`.
- Queue: `QUEUE_CONNECTION=database`, the `jobs` and `failed_jobs` tables exist. The queue worker already runs in the `app` container under supervisord (`queue:listen` in dev, `queue:work` in prod), both started with `--tries=3 --timeout=90`. A job class's own `$tries` and `$timeout` properties override those worker flags per job.
- The database queue's `retry_after` (config `queue.connections.database.retry_after`, env `DB_QUEUE_RETRY_AFTER`, default 90 s) must be **greater** than the job's timeout, otherwise the worker hands the still-running job to a second worker.
- The `local` disk is private (`storage/app/private`), has `'serve' => true`, so `Storage::disk('local')->temporaryUrl()` works and files are served through a signed route to logged-in browsers only in the sense that the URL is unguessable and expires. That is acceptable for this app.
- `laravel/ai` provides `Image::fromStorage(string $path, ?string $disk)` for files on a Laravel disk; the Ollama and Anthropic gateways accept it like `Image::fromPath()`.
- The extractor's prompt timeout is 180 s (`InventoryExtractor::timeout()`).

## Decided (by the user)

- Extraction runs in a queue job; the upload only creates the import and triggers the job.
- After upload the user is redirected to a page showing the import's status.
- An import is its own record, distinct from the future inventory item.
- Scope: async processing plus status page. No editing.

## Decided (my calls; say if wrong)

### Data

- **Model `App\Models\Import`**, table `imports`: `id`, `user_id` (FK users, the uploader; cascade on delete), `status` (string, backed by enum below), `photos` (JSON array of storage paths on the `local` disk, in upload order), `title` (string, nullable), `description` (text, nullable), `error` (text, nullable; the exception message of the last failed run), `created_at`, `updated_at`. Factory with states `pending`, `running`, `completed` (title+description filled), `failed` (error filled).
- **Enum `App\Enums\ImportStatus`** (string-backed): `Pending = 'pending'`, `Running = 'running'`, `Completed = 'completed'`, `Failed = 'failed'`. Implements Filament's `HasLabel` and `HasColor`: labels "Wartet", "Läuft", "Fertig", "Fehlgeschlagen"; colours gray, warning, success, danger.
- **Photos** are stored by Filament's `FileUpload` (`storeFiles` on, `disk('local')`, `directory('imports')`, `visibility('private')`, random file names) at upload time, before the record exists. Browser-side resize to 2048 px, the accepted types (JPEG, PNG, WebP, GIF), 1–6 files and 12 MB per file stay exactly as on the current page. Photos are never deleted in this round.

### Flow

1. **Create page** (`/importe/create`, title "Erfassen"): one `FileUpload` field as above. On submit the import is created with `user_id = auth()->id()`, `status = pending`, `photos = [...]`, then `ExtractImport::dispatch($import)` from the create page's after-create hook, then redirect to the import's view page. Filament's default "created" notification is replaced by none or a short German one ("Import angelegt"); builder's choice.
2. **Job `App\Jobs\ExtractImport`** (`ShouldQueue`, constructor `public Import $import`): `$tries = 1`, `$timeout = 200` (above the extractor's 180 s). `handle()`: set status `running`; build one `Image::fromStorage($path, 'local')` per photo; call `InventoryExtractor::make()->prompt(<same prompt text as today>, $attachments)->toArray()`; apply the same strict `title`/`description` string guard as the current page (incomplete answer = failure, never a half result); write `title`, `description`, `error = null`, status `completed`. Any `Throwable` propagates after `report()`; the job's `failed(?Throwable $e)` method sets status `failed` and `error` to the message (fallback text when `$e` is null). The job must not be `ShouldBeUnique` or batched; keep it plain.
3. **`DB_QUEUE_RETRY_AFTER=300`** added to `.env.example` and to the `app` service environment in `compose.yaml` (so a fresh checkout gets it), satisfying the retry_after > timeout rule. The supervisord commands are left as they are; the job's own properties override `--tries`/`--timeout`.
4. **View page** (`/importe/{id}`): infolist with the photos (image entries from the `local` disk; Filament resolves private-disk previews through temporary URLs), the status as a badge, title and description (hidden while empty), the error (shown only when failed), created/updated timestamps. While status is `pending` or `running` the page polls every 5 s (`wire:poll.5s` on the infolist's wrapping section, present only in those states, so polling stops by itself once the import is done or failed). The record must be re-read from the database on every poll; Livewire re-hydrates Eloquent models from the database per request, so this is automatic — the builder verifies it in a test.
5. **Retry** header action on the view page, visible only when status is `failed`: sets status `pending`, clears `error`, `title`, `description`, saves, dispatches `ExtractImport` again, notifies "Erneut gestartet". No confirmation modal.
6. **List page** (`/importe`): table with first-photo thumbnail, title (placeholder "—" while empty), status badge, created-at; default sort `created_at` desc; row click opens the view page; header action "Erfassen" leads to the create page. Table polling every 10 s is optional (builder).

### Panel wiring

- **Resource `App\Filament\Resources\Imports\ImportResource`** (or whatever layout `php artisan make:filament-resource Import --view` generates in Filament 5): model label "Import", plural "Importe", navigation label "Importe", slug `importe`, pages list/create/view. No edit page (edit is a later round; `canEdit` returns false). No policy: every verified user may list, create, view and retry (single household).
- The current custom page `app/Filament/Pages/ExtractItem.php`, its Blade view and its test file `tests/Feature/Panel/ExtractItemPageTest.php` are **removed** together with the synchronous flow; their coverage moves to the resource and job tests below. Approving this document approves that deletion. `/erfassen` stops existing; the navigation entry is "Importe" and the create page is titled "Erfassen".
- `CLAUDE.md` (project section) and `README.md` are updated: imports resource, job, polling, `DB_QUEUE_RETRY_AFTER`, where photos live.

### Tests (Pest, in-memory SQLite, `Storage::fake('local')`, `Queue::fake()` where the job is not under test)

- Create page: requires login and verification; validation (none, non-image, more than 6) as today; a valid submit creates one `Import` with status pending, the uploader as `user_id`, as many stored files on the `local` disk as uploaded photos in upload order, pushes exactly one `ExtractImport` for that import, and redirects to the view page.
- Job: with `InventoryExtractor::fake()` returning title and description, the import ends `completed` with those values and the agent was prompted once with as many attachments as photos; with an agent that throws, the import ends `failed` with the message in `error` and the exception reported; with an incomplete or non-string answer, the import ends `failed`. Run the job synchronously via `(new ExtractImport($import))->handle()` plus a direct call of `failed()` where the exception path is tested, or through `Queue::fake()`-less `dispatchSync`; builder's choice, but the failed-state test must exercise `failed()`.
- View page: renders status label and photos for a pending import; shows title and description for a completed one; shows the error and the Retry action for a failed one and hides Retry otherwise; Retry resets the record and pushes one `ExtractImport`; the poll attribute is present for pending/running and absent for completed/failed.
- List page: renders, lists imports newest first.
- Existing extractor tests stay untouched.

## Left to the builder

- Exact Filament component choices (sections, grid, badge rendering), German copy of labels and notifications beyond the ones fixed above.
- How the poll attribute is attached (schema component `extraAttributes` vs. a custom page view), as long as the behaviour in Flow 4 holds.
- Thumbnail sizing in the list.
- Whether to show the raw exception class in `error` or only the message.

## Acceptance

1. Logged-in, verified user opens "Importe" → "Erfassen", uploads two photos, is redirected within a second to `/importe/{id}` showing "Wartet"; without reloading, the badge turns "Läuft" and then "Fertig" with a German title and description (local Ollama/gemma4).
2. With Ollama stopped, the same upload ends in "Fehlgeschlagen" with the connection error visible, the Retry button appears, and after starting Ollama and clicking Retry the import completes.
3. The import appears in the list, newest first. The photo URLs are not reachable as plain `/storage/...` paths.
4. `php artisan test`, Pint and PHPStan are green.
