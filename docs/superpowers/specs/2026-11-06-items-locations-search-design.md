# Storage locations, items and search — design

Date: 2026-11-06. Status: draft for approval. Builds on `2026-11-05-async-import-design.md`.

## Intent

Tory exists to answer one question: **where is that thing?** The user types something like "Raspberry Pi Gehäuse" and gets back the item and the place it lies in, for example box 12 "Elektronik-Kiste".

Today Tory can turn 1–6 photos into a German title and description (an **import**), and stops there. This round adds the three missing pieces:

1. **Storage locations** ("Lagerorte"): physical containers such as a box or a cupboard, each with a number and a name.
2. **Items** ("Gegenstände"): an import, once checked, becomes an item that lies in exactly one storage location.
3. **Search** over items, by keyword and by meaning.

How the user works (their words, paraphrased): they pack a box and take photos while packing; later they sit down and inventory piece by piece. Which photo belongs to which box is a human matter and deliberately not modelled: "Das ist ein menschlicher Prozess. Das müssen wir jetzt nicht in Software gießen."

## Goals

- Create and maintain storage locations; each has a fixed number and a fixed address in Tory.
- Open a storage location and see the list of items inside it. This page is what a QR code on the box will point to later.
- Choose the storage location when uploading photos, with the last one used preselected, so a whole box goes through quickly.
- Check and correct title, description and storage location of a finished import, then turn it into an item.
- Browse items; type into a search field above the item list and get hits with thumbnail, title and storage location (number and name).
- Search finds items by the words typed and by meaning (a query that shares no word with the description can still hit).

## Non-goals (for now)

- **Labels:** generating, laying out or printing the label with number, name and QR code. The user decided this is a later session. This round only guarantees the stable number and address the label will need.
- Nested storage locations (box in cupboard in cellar). Locations are a flat list.
- Several distinct items per photo or per import. The 1–6 photos of an import are views of the same single item.
- Guessing the storage location from photos, EXIF data, or anything else.
- Meilisearch, Typesense or any other search service; Laravel Scout.
- Search in Filament's global search bar, search over imports, search over storage locations.
- Adding, removing or reordering photos on an existing item.
- Quantities, categories, tags, loan tracking, history of moves.
- Per-user visibility rules (single household, as before).

## Anti-goals

- **Search that breaks when the embedding service is down.** If the query cannot be embedded, the search silently falls back to keyword matching. An item whose embedding is missing is still found by keyword.
- **An item without a storage location.** The whole point is the location; an item can never be saved without one.
- **Orphaned or double-owned photos.** At any time a photo file belongs to exactly one import or one item, and deleting that record deletes the file.
- **A storage location's number or address changing** after it was created. A printed label must stay valid forever.
- **Losing an item's content because an AI call failed.** Turning an import into an item must succeed without any AI service.
- Invented content: the extractor stays unchanged.

## Constraints (verified in the repo on 2026-11-06)

- Stack: Laravel 13.17+, Filament 5, Livewire 4, `laravel/ai` 1.3, Postgres 18, Pest, Pint, Larastan; everything runs via `docker compose exec app ...`. UI copy is German.
- Laravel 13 has vector search built in: `$table->vector('embedding', dimensions: N)`, `Schema::ensureVectorExtensionExists()`, the `AsVector` cast, `whereVectorSimilarTo`, `selectVectorDistance`, `orderByVectorDistance`. All of it requires Postgres with the `pgvector` extension; every other schema grammar throws "This database driver does not support the vector type."
- The current database image `postgres:18` (Debian trixie) does **not** ship `pgvector` (`pg_available_extensions` has no `vector` row).
- The test suite currently runs on in-memory SQLite (`phpunit.xml`), which cannot create a vector column or run a vector query.
- Anthropic offers no embeddings. `laravel/ai` supports embeddings through Ollama (and OpenAI, Gemini, Mistral, Voyage, Cohere, Jina, …): `Embeddings::for([...])->dimensions(N)->generate($provider, $model)`. `config/ai.php` has `default_for_embeddings => 'openai'`, for which no key is configured. `Embeddings::fake()` exists for tests and accepts a closure.
- The user's local Ollama has no embedding model installed yet (only chat models).
- Filament tables accept a custom search via `$table->searchUsing(Closure)`.
- Queue timing invariant from the previous round: job timeouts < worker `--timeout=210` < `retry_after=300`.
- The dev database holds six imports; they have no storage location.

## Decided (by the user)

- Storage locations are tangible things (a box, a cupboard) carrying **a number, a name and a QR code** that deep-links into Tory.
- Scanning the code shows **the list of items in that location**.
- **Tory generates the label**, the user prints it; built in a later session.
- Storage locations are a **flat list**; an item lies in **exactly one** location.
- One import = one item; several photos are **views of the same item**.
- The storage location is **chosen by hand at upload**, with the **last one used preselected**.
- Title and description **can be corrected before** the import becomes an item.
- Search is a **field above the item list**; a hit shows **thumbnail, title and storage location with number and name**.
- Search works **by keyword and semantically**, using **Laravel and Postgres rather than Meilisearch** (my recommendation, accepted).

## Decided (my calls; say if wrong)

### Infrastructure

- **Database image:** `compose.yaml` switches `db` from `postgres:18` to the `pgvector` image for Postgres 18 on the same Debian release as today (trixie), so the existing `db_data` volume keeps working without a collation mismatch. Builder verifies the exact tag (`pgvector/pgvector:pg18-trixie` or equivalent). Any production database needs `pgvector` as well.
- **Tests move from in-memory SQLite to Postgres.** The suite runs against a separate database `tory_testing` on the `db` service, because vector columns and vector queries cannot run on SQLite and the search is the feature most in need of real tests. This replaces a convention documented in `CLAUDE.md`; approving this document approves that change. Requirements: on a fresh checkout `docker compose up -d` followed by `php artisan test` works without manual steps; for an existing volume a one-off command is documented. `RefreshDatabase` stays. The CI workflow `.github/workflows/tests.yml`, which forces in-memory SQLite today, switches to a `pgvector` Postgres service as well.
- **Embedding provider and model:** configured separately from the extractor in `config('ai.inventory.*')`: `embedding_provider` (`INVENTORY_EMBEDDING_PROVIDER`, default `ollama`), `embedding_model` (`INVENTORY_EMBEDDING_MODEL`, default `bge-m3`), with a fixed dimension of **1024**. `bge-m3` is multilingual, handles German well and needs no query/document prefixes. The user runs `ollama pull bge-m3` once. Changing to a model with another dimension requires a migration and a full re-embed; that is accepted.
- **No new Composer dependency.** Search is built with the query builder, not Scout.

### Data

- **`App\Models\StorageLocation`**, table `storage_locations`: `id`, `name` (string, required), `note` (text, nullable; free text such as "Keller, Regal links"), timestamps. **The number is the `id`**: assigned by Tory, never editable, never reused. Shown as "Nr. 12". Gaps after deletions are fine. Has many items and imports. Factory.
- **`App\Models\Item`**, table `items`: `id`, `storage_location_id` (FK, required, deletion of the location restricted), `title` (string, required), `description` (text, nullable), `photos` (JSON array of paths on the `local` disk, in order; first is the thumbnail), `embedding` (vector(1024), nullable), timestamps. No `user_id`. Factory.
- **`imports.storage_location_id`**: new nullable FK (null on location delete). Nullable because existing imports have none.
- **Photo ownership:** converting an import hands its photo paths to the item; files are not moved or copied (they stay under `imports/` on the `local` disk). Deleting an item or an import deletes its files.

### Storage locations ("Lagerorte", slug `lagerorte`)

- List: number, name, note, number of items; default order by number.
- Create and edit: name and note only.
- **View page `/lagerorte/{id}`** is the future QR target: shows "Nr. {id}", name, note and the items inside (thumbnail and title, newest first; a row opens the item). It must be comfortable on a phone. An unauthenticated visit goes through login and lands back on this page.
- Delete is possible only while the location holds no items; otherwise the action explains why. Imports pointing at a deleted location lose the reference.

### From import to item

- **Upload** (`/importe/create`): a required "Lagerort" select above the photos, showing "Nr. · Name", searchable, with an inline "create new location" option. It is preselected with the location the current user chose last; that memory is per user and survives logout (mechanism is the builder's choice, e.g. a nullable column on `users`).
- **Import list and view page** show the storage location.
- **"Übernehmen"** header action on the import view page, visible when the import is `completed` or `failed` (a failed import can be filled in by hand). It opens a form prefilled from the import: title (required), description (optional), Lagerort (required). For an import without a location (the six existing ones), the Lagerort field falls back to the user's last-used location. On submit, in one transaction: create the item with those values and the import's photos, delete the import row (not the files). Then redirect to the import list with a notification "Gegenstand angelegt". The import list thereby becomes the inbox of things still to be checked. There is no separate edit page for imports.
- **"Löschen"** header action on the import view page, visible when `completed` or `failed`, with confirmation: deletes the import and its photo files. Without it a bad upload would sit in the inbox forever.

### Items ("Gegenstände", slug `gegenstaende`)

- List: thumbnail, title, storage location as "Nr. 12 · Elektronik-Kiste" (links to the location), created-at; newest first when no search is active.
- View page: photos, title, description, storage location (link).
- Edit: title, description, storage location (this is how an item moves to another box).
- Delete with confirmation: removes the item and its photo files.
- Items are created only through "Übernehmen"; there is no create page.
- Navigation order: Gegenstände, Lagerorte, Importe.

### Search

- **One field** above the item list (Filament's table search, custom `searchUsing`; the table needs an explicit `->searchable()` for the field to appear). Empty input shows the normal list.
- **Keyword part:** the input is split on whitespace; an item matches when every word occurs, case-insensitively, as a substring of its title or description. Substring matching rather than Postgres full-text search because German compounds ("Kunststoffgehäuse" for "Gehäuse"), type codes ("M3", "HDMI") and half-typed words matter more here than stemming, and the table is small.
- **Semantic part:** the input is embedded with the configured model; an item matches when the cosine similarity between its embedding and the query is at least `config('ai.inventory.search_min_similarity')` (`INVENTORY_SEARCH_MIN_SIMILARITY`; start at 0.5 and tune against real items during the live check).
- **Combined result:** items matching either part. Keyword matches come first, then semantic-only matches; within each group the most similar first, items without an embedding last.
- **Failure:** if embedding the query throws, the exception is reported and the result is keyword-only. No error is shown. The search-time embedding call gets a short timeout (a few seconds, instead of the SDK's 30 s default), so a cold or busy embedding service degrades to keyword search rather than hanging the list.
- **Embedding items:** a queued job `App\Jobs\EmbedItem` embeds "title, blank line, description" and stores the vector. It is dispatched after an item is created and after an edit that changed title or description; such an edit first sets `embedding` to null, so an embedding is always either absent or matching the current text. `$tries = 3` with backoff, `$timeout = 60`. A failed job leaves the item keyword-searchable.
- **Catch-up:** an Artisan command `items:embed` embeds all items without an embedding (`--all` re-embeds everything, for a model change). The scheduler runs it hourly, so items created while the embedding service was off heal by themselves.
- One class owns "text to vector with the configured provider, model and dimension"; the job, the command and the search all use it.
- No vector index for now; an exact scan is fast and accurate at household size.

### Tests (Pest, Postgres `tory_testing`, `Storage::fake('local')`, `Queue::fake()` unless the job is under test, `Embeddings::fake()`)

- Storage locations: create, edit, list with item counts; view page lists exactly its items; delete blocked while items exist; guest visiting the view page is sent to login.
- Upload: location required; preselected with the user's last choice; chosen location stored on the import.
- Übernehmen: visible only for completed/failed; creates the item with edited values, photos and location; removes the import row but keeps the files; dispatches `EmbedItem`; validation (title, location).
- Import delete: removes row and files; hidden while pending/running.
- Items: list, view, edit (move location), delete removes files; editing title or description nulls the embedding and dispatches `EmbedItem`, editing only the location does not.
- `EmbedItem` and `items:embed`: store a vector of the right dimension; the command skips items that already have one unless `--all`.
- Search, with `Embeddings::fake()` returning hand-made vectors per text: keyword hit by substring and by several words; semantic hit without any shared word; keyword hits ordered before semantic-only hits; item without embedding found by keyword; embedding failure falls back to keyword results and reports the exception; below-threshold items are not returned.
- Existing import and extractor tests keep passing on Postgres.

### Documentation

- `CLAUDE.md` (project section) and `README.md` are updated: new resources, conversion flow, search, embedding config and `ollama pull bge-m3`, `pgvector` image, Postgres test database.

## Suggested build order

Each step leaves the app working: (1) `pgvector` image and Postgres test database; (2) storage locations; (3) location at upload, items, Übernehmen, import delete; (4) embeddings and search.

## Left to the builder

- Exact Filament components and layout, German copy beyond the labels fixed above, thumbnail sizes, whether the item list shows a description excerpt.
- Whether items inside a location are shown with a relation manager or a plain table.
- How the last-used location is remembered, and how `tory_testing` is created.
- Search debounce, caching of query embeddings, whether semantic-only hits are visually marked.
- Backoff values, and whether `items:embed` works in chunks.

## Acceptance

1. The user creates the location "Elektronik-Kiste"; it gets a number, say 1, and lives at `/lagerorte/1`.
2. "Erfassen": location 1 chosen, two photos uploaded. For the next upload location 1 is already selected.
3. When the import is "Fertig", "Übernehmen" shows title, description and location; the user corrects the title and confirms. The import leaves the import list; the item appears under "Gegenstände" and on `/lagerorte/1`.
4. Typing a word from the title into the search field shows the item with thumbnail, title and "Nr. 1 · Elektronik-Kiste". A query describing the item in other words (no shared word) also finds it.
5. With Ollama stopped, the keyword search still works and converting an import still works; after Ollama is back, the item gets its embedding within the hour or after `items:embed`.
6. Opening `/lagerorte/1` on a phone while logged out leads through login to the list of items in that box.
7. Deleting the item removes its photos from disk; the location can then be deleted.
8. `php artisan test`, Pint and PHPStan are green.
