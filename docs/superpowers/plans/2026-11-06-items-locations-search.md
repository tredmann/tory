# Storage Locations, Items and Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a checked import become an item that lies in a numbered storage location, and make items findable by keyword and by meaning from a search field above the item list.

**Architecture:** Two new models, `StorageLocation` (its `id` is its number) and `Item` (photos as JSON paths, a nullable `vector(1024)` embedding), each with a Filament resource. The import create form gains a storage-location select; the import view page gains "Übernehmen" (creates the item, removes the import row, keeps the files) and "Löschen". A queued `EmbedItem` job fills the embedding through one `ItemEmbedder` class; `Item::search()` combines case-insensitive substring matching with pgvector cosine similarity and falls back to keywords when embedding fails. Postgres switches to the `pgvector` image and the test suite moves from SQLite to a Postgres database `tory_testing`.

**Tech Stack:** Laravel 13.40, Filament 5.11 (Schemas API), Livewire 4, `laravel/ai` 1.3 (`Embeddings`), Postgres 18 with `pgvector`, Ollama `bge-m3`, database queue, Pest 4, Pint, Larastan.

**Spec:** `docs/superpowers/specs/2026-11-06-items-locations-search-design.md`

## Global Constraints

- Every PHP command runs inside Docker: `docker compose exec app php artisan ...`, `docker compose exec app ./vendor/bin/pint --dirty --format agent`, `docker compose exec app ./vendor/bin/phpstan analyse`. Pint and PHPStan must be clean before each commit.
- Work on a branch `items-locations-search` created from `master` (no remote exists). Commit with `git -c core.hooksPath=/dev/null commit`; messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- No new Composer or npm dependency. No Scout, no Meilisearch.
- `InventoryExtractor` and the `ExtractImport` job are not changed.
- Create files with `php artisan make:* --no-interaction` (`make:model`, `make:migration`, `make:factory`, `make:job`, `make:command`, `make:test --pest`, `make:filament-resource`, `make:filament-relation-manager`). Follow sibling files for structure (`app/Filament/Resources/Imports/` is the reference resource; `app/Models/Import.php` the reference model).
- UI copy is German. Resource labels: "Lagerort"/"Lagerorte" (slug `lagerorte`), "Gegenstand"/"Gegenstände" (slug `gegenstaende`). Navigation order: Gegenstände (1), Lagerorte (2), Importe (3).
- A storage location's number is its `id`, shown as `Nr. 12`; with name: `Nr. 12 · Elektronik-Kiste`. Neither the number nor the address `/lagerorte/{id}` may ever change.
- Photos stay on disk `local`, visibility `private`, under `imports/`, also after conversion. A photo file belongs to exactly one import or one item.
- An item always has a storage location (NOT NULL, restrict on delete).
- Embedding: provider `config('ai.inventory.embedding_provider')` (default `ollama`), model `config('ai.inventory.embedding_model')` (default `bge-m3`), dimension **1024**. Similarity threshold `config('ai.inventory.search_min_similarity')` (default `0.5`).
- Queue timing invariant: every job timeout < worker `--timeout=210` < `retry_after=300`.
- Tests: Pest on Postgres `tory_testing`, `RefreshDatabase` from `tests/Pest.php`, `Storage::fake('local')`, `Queue::fake()` unless the job is under test, panel pages via `Livewire::test(PageClass::class, [...])`. No test may reach a real AI provider. Postgres sequences are not reset between tests: never assert a literal id, always use the created model's key. After a `QueryException` Postgres aborts the test's transaction: a test that expects one must not query the database afterwards.
- Every verified user may do everything; no policy layer.
- Filament 5 API names in this plan were checked where stated; where a helper name is given "or its Filament 5 equivalent", look it up with Boost `search-docs` before use.

## Review Focus

1. **The test suite pointed at the dev database** (`tory`) would wipe the user's data on `RefreshDatabase`. Expected: tests refuse to run unless the database is `tory_testing`. Test in Task 1.
2. **Search input containing `%`, `_` or `\`.** Expected: treated as literal characters, never as wildcards. Test in Task 8.
3. **The embedding provider returns a vector of another length** (model changed in `.env` without a migration). Expected: the embedder throws, the job fails, the search falls back to keywords; no SQL error reaches the page. Tests in Tasks 7 and 8.
4. **An item without a description.** Expected: it is embedded from the title alone and found by a title keyword. Tests in Tasks 7 and 8.
5. **The user's last-used location was deleted.** Expected: the upload form shows no preselection and still works. Test in Task 5.

---

### Task 1: Postgres with pgvector for dev, tests and CI

**Files:**
- Modify: `compose.yaml` (service `db`), `phpunit.xml`, `tests/TestCase.php`, `.github/workflows/tests.yml`, `CLAUDE.md` (sections "Running the app", "Running commands", "Testing conventions")
- Create: `docker/postgres/init/01-create-testing-database.sql`, `tests/Feature/DatabaseTest.php`

**Interfaces:**
- Produces: a Postgres test database `tory_testing` with the `vector` extension available; `TestCase::beforeRefreshingDatabase()` guard. Later tasks rely on vector columns working in tests.

- [ ] **Step 1: Write the failing test** in `tests/Feature/DatabaseTest.php`

```php
test('tests run on the tory_testing Postgres database with pgvector available', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('tory_testing')
        ->and(DB::table('pg_available_extensions')->where('name', 'vector')->exists())->toBeTrue();
});

test('the suite refuses to refresh any database other than tory_testing', function () {
    config(['database.connections.pgsql.database' => 'tory']);

    expect(fn () => $this->beforeRefreshingDatabase())->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run it and see it fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/DatabaseTest.php`
Expected: FAIL (driver is `sqlite`).

- [ ] **Step 3: Switch the database image.** In `compose.yaml` set `db.image: pgvector/pgvector:pg18-trixie` (tag verified to exist; same Debian release as the current `postgres:18`, so the `db_data` volume stays valid) and mount `./docker/postgres/init:/docker-entrypoint-initdb.d:ro`. Create `docker/postgres/init/01-create-testing-database.sql` containing `CREATE DATABASE tory_testing OWNER tory;` (runs only on a fresh volume).

Run: `docker compose up -d db`, then `docker compose exec db createdb -U tory tory_testing` (one-off for the existing volume), then `docker compose exec db psql -U tory -d tory -Atc "select count(*) from imports"`.
Expected: the import count is unchanged (6).

- [ ] **Step 4: Point the suite at Postgres.** In `phpunit.xml` set `DB_CONNECTION` to `pgsql` and `DB_DATABASE` to `tory_testing` (host, user and password come from `.env`). In `tests/TestCase.php` add `protected function beforeRefreshingDatabase(): void` that throws a `RuntimeException` unless `config('database.connections.'.config('database.default').'.database') === 'tory_testing'`.

- [ ] **Step 5: Run the whole suite**

Run: `docker compose exec app php artisan test --compact`
Expected: PASS. If an existing test fails only because it assumed SQLite behaviour (literal ids, case-insensitive `LIKE`), fix the test, not the production code.

- [ ] **Step 6: CI.** In `.github/workflows/tests.yml` add a `services.postgres` container (`pgvector/pgvector:pg18-trixie`, `POSTGRES_DB: tory_testing`, `POSTGRES_USER: tory`, `POSTGRES_PASSWORD: secret`, port 5432, `pg_isready` health check) and give both the "Setup Application" and "Run CI Checks" steps `DB_CONNECTION: pgsql`, `DB_HOST: 127.0.0.1`, `DB_PORT: 5432`, `DB_DATABASE: tory_testing`, `DB_USERNAME: tory`, `DB_PASSWORD: secret`. (No remote exists, so this cannot be run; keep the change minimal.)

- [ ] **Step 7: Update `CLAUDE.md`:** `db` is Postgres 18 with `pgvector`; tests run on Postgres database `tory_testing` (created by the init script on a fresh volume, otherwise `docker compose exec db createdb -U tory tory_testing`); the suite refuses any other database.

- [ ] **Step 8: Commit** (`Run tests on Postgres with pgvector`), including the spec and this plan if not yet committed.

---

### Task 2: Data model

**Files:**
- Create: migrations `create_storage_locations_table`, `create_items_table`, `add_storage_location_id_to_imports_table`, `add_last_storage_location_id_to_users_table` (in this order); `app/Models/StorageLocation.php`, `app/Models/Item.php`; `database/factories/StorageLocationFactory.php`, `database/factories/ItemFactory.php`
- Modify: `app/Models/Import.php`, `app/Models/User.php`
- Test: `tests/Feature/Models/StorageLocationTest.php`, `tests/Feature/Models/ItemTest.php`, `tests/Feature/Models/ImportTest.php`

**Interfaces:**
- Produces:
  - `storage_locations`: `id`, `name` string, `note` text nullable, timestamps.
  - `items`: `id`, `storage_location_id` (FK, `restrictOnDelete`), `title` string, `description` text nullable, `photos` json, `embedding` `vector(1024)` nullable, timestamps. The migration calls `Schema::ensureVectorExtensionExists()` first.
  - `imports.storage_location_id`: nullable FK, `nullOnDelete`. `users.last_storage_location_id`: nullable FK to `storage_locations`, `nullOnDelete`.
  - `StorageLocation`: fillable `name`, `note`; `items(): HasMany<Item>`, `imports(): HasMany<Import>`; attribute `label` (string) = `"Nr. {id} · {name}"`.
  - `Item`: fillable `storage_location_id`, `title`, `description`, `photos`; casts `photos` => `array`, `embedding` => `AsVector::class`; `embedding` is hidden from array/JSON output (keeps 1024 floats out of Livewire form state); `storageLocation(): BelongsTo<StorageLocation>`.
  - `Import`: `storage_location_id` fillable; `storageLocation(): BelongsTo<StorageLocation>`.
  - `User`: `last_storage_location_id` (`int|null`, documented in the class PHPDoc).
  - **File ownership rule:** `Item` and `Import` each delete their photo files from the `local` disk in a `deleted` model event. `deleteQuietly()` therefore removes the row and keeps the files; Task 6 relies on exactly this.
  - Factories: `StorageLocation::factory()` (name of two words, note null); `Item::factory()` (`storage_location_id` => `StorageLocation::factory()`, a title, a description, `photos` => `['imports/item.jpg']`, no embedding).

- [ ] **Step 1: Write the failing tests**

```php
// StorageLocationTest
test('the label shows number and name', function () {
    $location = StorageLocation::factory()->create(['name' => 'Elektronik-Kiste']);
    expect($location->label)->toBe("Nr. {$location->id} · Elektronik-Kiste");
});

test('a location holding items cannot be deleted', function () {
    $item = Item::factory()->create();
    expect(fn () => $item->storageLocation->delete())->toThrow(QueryException::class);
});

test('deleting a location clears it from imports and users', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->create(['storage_location_id' => $location->id]);
    $user = User::factory()->create();
    $user->forceFill(['last_storage_location_id' => $location->id])->save();

    $location->delete();

    expect($import->fresh()->storage_location_id)->toBeNull()
        ->and($user->fresh()->last_storage_location_id)->toBeNull();
});

// ItemTest (Storage::fake('local') and Queue::fake() in beforeEach)
test('an item cannot exist without a storage location', function () {
    expect(fn () => Item::factory()->create(['storage_location_id' => null]))->toThrow(QueryException::class);
});

test('the embedding round-trips as 1024 floats and stays out of the array form', function () {
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->save();

    expect($item->fresh()->embedding)->toHaveCount(1024)
        ->and($item->fresh()->toArray())->not->toHaveKey('embedding');
});

test('deleting an item deletes its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $item = Item::factory()->create(['photos' => ['imports/x.jpg']]);

    $item->delete();

    Storage::disk('local')->assertMissing('imports/x.jpg');
});

// ImportTest additions (same fakes)
test('deleting an import deletes its photo files', /* same shape with Import::factory() */);

test('deleting an import quietly keeps its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    Import::factory()->create(['photos' => ['imports/x.jpg']])->deleteQuietly();

    Storage::disk('local')->assertExists('imports/x.jpg');
});
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models`
Expected: FAIL (classes do not exist).

- [ ] **Step 3: Create migrations, models and factories** as described under Interfaces. Run `docker compose exec app php artisan migrate` afterwards so the dev database has the tables.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models`
Expected: PASS.

- [ ] **Step 5: Commit** (`Add storage locations and items to the data model`).

---

### Task 3: Lagerorte resource

**Files:**
- Create (generator: `make:filament-resource StorageLocation --view --not-embedded --panel=admin`): `app/Filament/Resources/StorageLocations/StorageLocationResource.php`, `Schemas/StorageLocationForm.php`, `Schemas/StorageLocationInfolist.php`, `Tables/StorageLocationsTable.php`, `Pages/{List,Create,Edit,View}StorageLocation.php`
- Test: `tests/Feature/Panel/StorageLocationResourceTest.php`

**Interfaces:**
- Consumes: `StorageLocation`, `Item` (Task 2).
- Produces: `StorageLocationResource` with pages `index`, `create`, `view` (`/lagerorte/{record}`), `edit`; `StorageLocationForm::components(): array` returning the name and note fields (reused by Task 4's select).

Fixed values: slug `lagerorte`; navigation sort 2; form fields `name` (label "Name", required, max 255) and `note` (label "Notiz", textarea, optional). Table columns: `id` (label "Nr."), `name`, `note` (truncated), item count (label "Gegenstände"); default order `id` ascending; a row opens the view page; no bulk actions. View page: "Nr." (the id), "Name", "Notiz"; header actions Edit and Delete. Delete on a location that still holds items changes nothing and sends a danger notification titled `Der Lagerort enthält noch Gegenstände und kann nicht gelöscht werden.` (check in the delete action's `before` hook, then `$action->halt()`).

- [ ] **Step 1: Write the failing tests** (`beforeEach`: `Storage::fake('local')`, `Queue::fake()`, `actingAs(User::factory()->create())`)

```php
test('the list shows locations by number with their item counts', function () {
    [$first, $second] = StorageLocation::factory()->count(2)->create();
    Item::factory()->count(3)->create(['storage_location_id' => $second->id]);

    Livewire::test(ListStorageLocations::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertSee('3');
});

test('a location is created with name and note', function () {
    Livewire::test(CreateStorageLocation::class)
        ->fillForm(['name' => 'Elektronik-Kiste', 'note' => 'Keller, Regal links'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StorageLocation::sole())->name->toBe('Elektronik-Kiste')->note->toBe('Keller, Regal links');
});

test('the name is required', /* fillForm(['name' => '']) -> assertHasFormErrors(['name' => 'required']) */);

test('name and note can be edited', /* EditStorageLocation, call('save'), fresh() has new values */);

test('the view page shows number, name and note', function () {
    $location = StorageLocation::factory()->create(['name' => 'Schrank', 'note' => 'Flur']);

    Livewire::test(ViewStorageLocation::class, ['record' => $location->getRouteKey()])
        ->assertSee("Nr. {$location->id}")->assertSee('Schrank')->assertSee('Flur');
});

test('an empty location can be deleted', /* callAction('delete'); StorageLocation::count() === 0 */);

test('a location holding items is not deleted and says why', function () {
    $location = Item::factory()->create()->storageLocation;

    Livewire::test(ViewStorageLocation::class, ['record' => $location->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Der Lagerort enthält noch Gegenstände und kann nicht gelöscht werden.');

    expect($location->fresh())->not->toBeNull();
});

test('a guest opening a location is sent to login and back', function () {
    auth()->logout();
    $location = StorageLocation::factory()->create();

    $this->get("/lagerorte/{$location->id}")->assertRedirect('/login');
    expect(session('url.intended'))->toEndWith("/lagerorte/{$location->id}");
});
```

- [ ] **Step 2: Run and see them fail** — `docker compose exec app php artisan test --compact tests/Feature/Panel/StorageLocationResourceTest.php`; expected FAIL (classes missing).
- [ ] **Step 3: Generate and implement the resource** with the fixed values above.
- [ ] **Step 4: Run the tests** — same command; expected PASS.
- [ ] **Step 5: Commit** (`Add the Lagerorte resource`).

---

### Task 4: Gegenstände resource and the items inside a location

**Files:**
- Create (generator: `make:filament-resource Item --view --not-embedded --panel=admin`, then delete the generated create page): `app/Filament/Resources/Items/ItemResource.php`, `Schemas/ItemForm.php`, `Schemas/ItemInfolist.php`, `Tables/ItemsTable.php`, `Pages/{List,View,Edit}Item.php`
- Create: `app/Filament/Resources/StorageLocations/Schemas/StorageLocationSelect.php`, `app/Filament/Resources/StorageLocations/RelationManagers/ItemsRelationManager.php` (generator: `make:filament-relation-manager`)
- Modify: `StorageLocationResource::getRelations()`, `app/Filament/Resources/Imports/ImportResource.php` (navigation sort 3)
- Test: `tests/Feature/Panel/ItemResourceTest.php`, `tests/Feature/Panel/StorageLocationResourceTest.php`

**Interfaces:**
- Consumes: Task 2 models; `StorageLocationResource`, `StorageLocationForm::components()` (Task 3).
- Produces:
  - `ItemResource` with pages `index`, `view`, `edit`; `canCreate()` returns false; slug `gegenstaende`; navigation sort 1.
  - `StorageLocationSelect::make(string $name = 'storage_location_id'): Select` — label "Lagerort", required, searchable, options are all locations ordered by `id` keyed by id with `label` as text, value must exist in `storage_locations`, inline "create new" using `StorageLocationForm::components()` and returning the new location's id. Tasks 5 and 6 reuse it.
  - `ItemsTable::configure(Table $table): Table` (Task 8 adds the search to it).

Fixed values: item table columns: first photo (label "Foto"), `title` ("Titel"), `storageLocation.label` ("Lagerort", links to the location's view page), `created_at` ("Angelegt"); default sort `created_at` descending; a row opens the view page; the query eager-loads `storageLocation`. View page: photos, "Titel", "Beschreibung" (line breaks preserved as on the import page), "Lagerort" (link), "Angelegt"; header actions Edit and Delete (stock `DeleteAction`; files go through the model event). Edit form: `title` (required, max 255), `description` (textarea, optional), `StorageLocationSelect`. The relation manager on the location view page is read-only (no create, edit or delete), shows first photo and title, newest first, and a row opens the item's view page.

- [ ] **Step 1: Write the failing tests**

```php
// ItemResourceTest (same beforeEach as Task 3)
test('the list shows items newest first with their location', function () {
    $location = StorageLocation::factory()->create(['name' => 'Elektronik-Kiste']);
    $old = Item::factory()->create(['storage_location_id' => $location->id, 'created_at' => now()->subDay()]);
    $new = Item::factory()->create(['storage_location_id' => $location->id]);

    Livewire::test(ListItems::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->assertSee("Nr. {$location->id} · Elektronik-Kiste");
});

test('items cannot be created directly', function () {
    $this->get('/gegenstaende/create')->assertNotFound();
});

test('the view page shows title, description and location', /* assertSee each */);

test('editing changes the title and moves the item to another location', function () {
    $item = Item::factory()->create();
    $target = StorageLocation::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['title' => 'Neuer Titel', 'storage_location_id' => $target->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh())->title->toBe('Neuer Titel')->storage_location_id->toBe($target->id);
});

test('title and location are required when editing', /* assertHasFormErrors(['title', 'storage_location_id']) */);

test('deleting an item removes the row and its photo files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $item = Item::factory()->create(['photos' => ['imports/x.jpg']]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])->callAction('delete');

    expect(Item::count())->toBe(0);
    Storage::disk('local')->assertMissing('imports/x.jpg');
});

// StorageLocationResourceTest addition
test('the view page lists exactly the items inside the location', function () {
    $location = StorageLocation::factory()->create();
    $inside = Item::factory()->count(2)->create(['storage_location_id' => $location->id]);
    $elsewhere = Item::factory()->create();

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $location, 'pageClass' => ViewStorageLocation::class])
        ->assertCanSeeTableRecords($inside)
        ->assertCanNotSeeTableRecords([$elsewhere]);
});
```

- [ ] **Step 2: Run and see them fail** — `docker compose exec app php artisan test --compact tests/Feature/Panel/ItemResourceTest.php tests/Feature/Panel/StorageLocationResourceTest.php`.
- [ ] **Step 3: Implement** the resource, the select and the relation manager with the fixed values above.
- [ ] **Step 4: Run the tests** — same command; expected PASS.
- [ ] **Step 5: Commit** (`Add the Gegenstände resource and list items inside a location`).

---

### Task 5: Storage location at upload

**Files:**
- Modify: `app/Filament/Resources/Imports/Schemas/ImportForm.php`, `Pages/CreateImport.php`, `Tables/ImportsTable.php`, `Schemas/ImportInfolist.php`
- Test: `tests/Feature/Panel/CreateImportTest.php`, `tests/Feature/Panel/ListImportsTest.php`, `tests/Feature/Panel/ViewImportTest.php`

**Interfaces:**
- Consumes: `StorageLocationSelect::make()` (Task 4), `users.last_storage_location_id` (Task 2).
- Produces: every new import carries `storage_location_id`; the uploader's `last_storage_location_id` is set to it in `CreateImport::afterCreate()`.

Fixed values: the select is the first field of the create form, defaulting to the current user's `last_storage_location_id`. Import list and view page show the location as `storageLocation.label` (label "Lagerort", placeholder "—" when empty).

- [ ] **Step 1: Write the failing tests.** Existing create tests that submit successfully or check photo validation now need `'storage_location_id' => StorageLocation::factory()->create()->id` in `fillForm`. New tests in `CreateImportTest`:

```php
test('a storage location is required', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('a.jpg')], 'storage_location_id' => null])
        ->call('create')
        ->assertHasFormErrors(['storage_location_id' => 'required']);
});

test('the chosen location is stored on the import and remembered for the user', function () {
    $location = StorageLocation::factory()->create();

    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('a.jpg')], 'storage_location_id' => $location->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Import::sole()->storage_location_id)->toBe($location->id)
        ->and($this->user->fresh()->last_storage_location_id)->toBe($location->id);
});

test('the last used location is preselected', function () {
    $location = StorageLocation::factory()->create();
    $this->user->forceFill(['last_storage_location_id' => $location->id])->save();

    Livewire::test(CreateImport::class)->assertFormSet(['storage_location_id' => $location->id]); // or its Filament 5 equivalent
});

test('nothing is preselected after the last used location was deleted', function () {
    $location = StorageLocation::factory()->create();
    $this->user->forceFill(['last_storage_location_id' => $location->id])->save();
    $location->delete();

    Livewire::test(CreateImport::class)->assertFormSet(['storage_location_id' => null]);
});
```

Plus one assertion each in `ListImportsTest` and `ViewImportTest` that an import with a location shows `Nr. {id} · {name}`.

- [ ] **Step 2: Run and see them fail** — `docker compose exec app php artisan test --compact tests/Feature/Panel/CreateImportTest.php tests/Feature/Panel/ListImportsTest.php tests/Feature/Panel/ViewImportTest.php`.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests** — same command; expected PASS.
- [ ] **Step 5: Commit** (`Choose the storage location when uploading photos`).

---

### Task 6: Übernehmen and Löschen on the import page

**Files:**
- Modify: `app/Models/Import.php`, `app/Filament/Resources/Imports/Pages/ViewImport.php`
- Test: `tests/Feature/Models/ImportTest.php`, `tests/Feature/Panel/ViewImportTest.php`

**Interfaces:**
- Consumes: `StorageLocationSelect::make()` (Task 4); the quiet-delete file rule (Task 2).
- Produces: `Import::convertToItem(string $title, ?string $description, int $storageLocationId): Item` — in one database transaction creates the item with the given values and the import's `photos`, then removes the import row with `deleteQuietly()` so the files survive; returns the item. Header actions `convert` and `delete` on `ViewImport`.

Fixed values: action `convert`, label "Übernehmen", visible only for status `Completed` or `Failed`; modal form `title` (label "Titel", required, max 255), `description` (label "Beschreibung", textarea, optional), `StorageLocationSelect`; prefilled with the import's title, description and `storage_location_id`, the latter falling back to the current user's `last_storage_location_id` when the import has none. On submit: `convertToItem`, success notification `Gegenstand angelegt`, redirect to the import list. Action `delete` (stock `DeleteAction`, label "Löschen", with confirmation), visible only for `Completed` or `Failed`, redirects to the import list.

- [ ] **Step 1: Write the failing tests**

```php
// ImportTest
test('converting creates the item, removes the import and keeps the files', function () {
    Storage::disk('local')->put('imports/x.jpg', 'x');
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->completed()->create(['photos' => ['imports/x.jpg']]);

    $item = $import->convertToItem('Pi-Gehäuse', 'Schwarz', $location->id);

    expect($item)->title->toBe('Pi-Gehäuse')->description->toBe('Schwarz')
        ->photos->toBe(['imports/x.jpg'])->storage_location_id->toBe($location->id)
        ->and(Import::count())->toBe(0);
    Storage::disk('local')->assertExists('imports/x.jpg');
});

// ViewImportTest
test('convert and delete are offered only for completed and failed imports', function (string $state, bool $visible) {
    $import = Import::factory()->{$state}()->create();
    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()]);

    $visible
        ? $page->assertActionVisible('convert')->assertActionVisible('delete')
        : $page->assertActionHidden('convert')->assertActionHidden('delete');
})->with([['pending', false], ['running', false], ['completed', true], ['failed', true]]);

test('convert is prefilled from the import', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->completed()->create(['storage_location_id' => $location->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->mountAction('convert')
        ->assertActionDataSet([  // or its Filament 5 equivalent
            'title' => 'Raspberry Pi 4',
            'description' => 'Grüne Platine mit vier USB-Anschlüssen.',
            'storage_location_id' => $location->id,
        ]);
});

test('convert falls back to the last used location when the import has none', /* user's last_storage_location_id is prefilled */);

test('convert with a corrected title creates the item and returns to the import list', function () {
    $location = StorageLocation::factory()->create();
    $import = Import::factory()->completed()->create(['storage_location_id' => $location->id]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => 'Raspberry Pi 4 Model B'])
        ->assertHasNoActionErrors()
        ->assertNotified('Gegenstand angelegt')
        ->assertRedirect(ImportResource::getUrl('index'));

    expect(Item::sole())->title->toBe('Raspberry Pi 4 Model B')->storage_location_id->toBe($location->id)
        ->and(Import::count())->toBe(0);
});

test('a failed import can be filled in by hand', /* callAction('convert', data: title + location) creates the item */);

test('convert requires title and location', function () {
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('convert', data: ['title' => '', 'storage_location_id' => null])
        ->assertHasActionErrors(['title' => 'required', 'storage_location_id' => 'required']);

    expect(Item::count())->toBe(0)->and(Import::count())->toBe(1);
});

test('deleting an import removes the row and its photo files', /* callAction('delete'); assertMissing; redirect to index */);
```

- [ ] **Step 2: Run and see them fail** — `docker compose exec app php artisan test --compact tests/Feature/Models/ImportTest.php tests/Feature/Panel/ViewImportTest.php`.
- [ ] **Step 3: Implement** `convertToItem` and the two actions.
- [ ] **Step 4: Run the tests** — same command; expected PASS.
- [ ] **Step 5: Commit** (`Turn imports into items and allow deleting imports`).

---

### Task 7: Embeddings

**Files:**
- Create: `app/Ai/ItemEmbedder.php`, `app/Jobs/EmbedItem.php`, `app/Console/Commands/EmbedItems.php`
- Modify: `config/ai.php` (`inventory` block), `.env.example`, `app/Models/Item.php`, `routes/console.php`, `tests/TestCase.php`
- Test: `tests/Feature/Ai/ItemEmbedderTest.php`, `tests/Feature/Jobs/EmbedItemTest.php`, `tests/Feature/Console/EmbedItemsCommandTest.php`

**Interfaces:**
- Consumes: `Item` (Task 2).
- Produces:
  - Config: `ai.inventory.embedding_provider` (`INVENTORY_EMBEDDING_PROVIDER`, default `ollama`), `ai.inventory.embedding_model` (`INVENTORY_EMBEDDING_MODEL`, default `bge-m3`), `ai.inventory.search_min_similarity` (`INVENTORY_SEARCH_MIN_SIMILARITY`, float, default `0.5`). Same keys, commented with the defaults, in `.env.example`.
  - `App\Ai\ItemEmbedder` (final): `public const int DIMENSIONS = 1024;` `public function embed(string $text, ?int $timeout = null): array` (returns `list<float>`; calls `Embeddings::for([$text])->dimensions(self::DIMENSIONS)`, applies `->timeout($timeout)` when given, `->generate(provider, model)` from config; throws `RuntimeException` when the returned vector does not have 1024 entries). `public function textFor(Item $item): string` — the title, and when a description is present, a blank line and the description.
  - `App\Jobs\EmbedItem` (`ShouldQueue`, constructor `public Item $item`): `$tries = 3`, `$backoff = [10, 60]`, `$timeout = 60`, `$deleteWhenMissingModels = true`; `handle(ItemEmbedder $embedder)` stores the vector on the item without firing model events.
  - `Item` model events: on **create**, dispatch `EmbedItem` after commit; on **update** that changed `title` or `description`, set `embedding` to null as part of that save and dispatch `EmbedItem` after commit. An update of other columns does neither.
  - Command `items:embed {--all}`: embeds, synchronously and one by one, every item without an embedding (with `--all`: every item); a failure for one item is reported (`report()`), printed as a warning and does not stop the rest; prints how many were embedded; exit code 0. Scheduled hourly with `withoutOverlapping()` in `routes/console.php`.
  - `tests/TestCase.php::setUp()` calls `Embeddings::fake()` so no test reaches a real provider (creating an item with the sync test queue would otherwise call Ollama).

- [ ] **Step 1: Write the failing tests**

```php
// ItemEmbedderTest
test('it embeds text with the configured provider, model and dimension', function () {
    config(['ai.inventory.embedding_provider' => 'ollama', 'ai.inventory.embedding_model' => 'bge-m3']);
    Embeddings::fake();

    $vector = app(ItemEmbedder::class)->embed('Raspberry Pi Gehäuse');

    expect($vector)->toHaveCount(1024);
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt) => $prompt->contains('Raspberry Pi Gehäuse') && $prompt->dimensions === 1024);
    // also assert provider `ollama` and model `bge-m3` on the prompt, using whatever EmbeddingsPrompt exposes
});

test('it rejects a vector of another length', function () {
    Embeddings::fake([[array_fill(0, 768, 0.1)]]);

    expect(fn () => app(ItemEmbedder::class)->embed('x'))->toThrow(RuntimeException::class);
});

test('the text is title, blank line, description', function () {
    $item = Item::factory()->make(['title' => 'Pi-Gehäuse', 'description' => 'Schwarz, Kunststoff']);
    expect(app(ItemEmbedder::class)->textFor($item))->toBe("Pi-Gehäuse\n\nSchwarz, Kunststoff");
});

test('an item without a description is embedded from its title alone', function () {
    $item = Item::factory()->make(['title' => 'Pi-Gehäuse', 'description' => null]);
    expect(app(ItemEmbedder::class)->textFor($item))->toBe('Pi-Gehäuse');
});

// EmbedItemTest
test('the job stores a 1024-dimension embedding', function () {
    Queue::fake();
    $item = Item::factory()->create();

    (new EmbedItem($item))->handle(app(ItemEmbedder::class));

    expect($item->fresh()->embedding)->toHaveCount(1024);
});

test('the job respects the queue timing invariant', function () {
    $job = new EmbedItem(Item::factory()->make());
    expect($job->tries)->toBe(3)->and($job->timeout)->toBe(60)->and($job->timeout)->toBeLessThan(210);
});

test('creating an item queues its embedding', function () {
    Queue::fake();
    $item = Item::factory()->create();

    Queue::assertPushed(EmbedItem::class, fn (EmbedItem $job) => $job->item->is($item));
});

test('changing title or description clears the embedding and queues a new one', function (string $field) {
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update([$field => 'Etwas anderes']);

    expect($item->fresh()->embedding)->toBeNull();
    Queue::assertPushed(EmbedItem::class, 2); // once on create, once on update
})->with(['title', 'description']);

test('moving an item keeps its embedding and queues nothing', function () {
    Queue::fake();
    $item = Item::factory()->create();
    $item->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $item->update(['storage_location_id' => StorageLocation::factory()->create()->id]);

    expect($item->fresh()->embedding)->not->toBeNull();
    Queue::assertPushed(EmbedItem::class, 1);
});

test('a failing embedding leaves the item in place without an embedding', function () {
    Queue::fake();
    $item = Item::factory()->create();
    Embeddings::fake(fn () => throw new RuntimeException('down'));

    expect(fn () => (new EmbedItem($item))->handle(app(ItemEmbedder::class)))->toThrow(RuntimeException::class);
    expect($item->fresh()->embedding)->toBeNull();
});

// EmbedItemsCommandTest (Queue::fake() so creation does not embed)
test('items:embed fills only missing embeddings', function () {
    $missing = Item::factory()->create();
    $present = Item::factory()->create();
    $present->forceFill(['embedding' => array_fill(0, 1024, 0.5)])->saveQuietly();

    $this->artisan('items:embed')->assertSuccessful();

    expect($missing->fresh()->embedding)->toHaveCount(1024)
        ->and($present->fresh()->embedding)->toBe(array_fill(0, 1024, 0.5));
});

test('items:embed --all re-embeds everything', /* $present's embedding differs afterwards */);

test('items:embed carries on after one item fails', function () {
    Exceptions::fake();
    [$first, $second] = Item::factory()->count(2)->create();
    $calls = 0;
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$calls) {
        return ++$calls === 1 ? throw new RuntimeException('down') : [Embeddings::fakeEmbedding(1024)];
    });

    $this->artisan('items:embed')->assertSuccessful();

    expect(Item::whereNotNull('embedding')->count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

test('items:embed is scheduled hourly', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('items:embed');
});
```

- [ ] **Step 2: Run and see them fail** — `docker compose exec app php artisan test --compact tests/Feature/Ai/ItemEmbedderTest.php tests/Feature/Jobs/EmbedItemTest.php tests/Feature/Console/EmbedItemsCommandTest.php`.
- [ ] **Step 3: Implement** config, embedder, job, model events, command, schedule and the global `Embeddings::fake()`.
- [ ] **Step 4: Run the whole suite** — `docker compose exec app php artisan test --compact`; expected PASS (earlier tasks' tests now create items with the events active).
- [ ] **Step 5: Commit** (`Embed items for semantic search`).

---

### Task 8: Search

**Files:**
- Modify: `app/Models/Item.php`, `app/Filament/Resources/Items/Tables/ItemsTable.php`
- Test: `tests/Feature/Models/ItemSearchTest.php`, `tests/Feature/Panel/ItemResourceTest.php`

**Interfaces:**
- Consumes: `ItemEmbedder::embed(string $text, ?int $timeout = null): array`, `config('ai.inventory.search_min_similarity')` (Task 7); `ItemsTable` (Task 4).
- Produces: a local query scope usable as `Item::query()->search(string $term)`.

Behaviour of `search`, which signature and tests do not fully determine:

1. Trim the term. If empty, leave the query untouched (and embed nothing).
2. **Keyword condition:** split on whitespace; for every word require `title` or `description` to contain it, case-insensitively (`whereLike` is case-insensitive on Postgres). Escape `\`, `%` and `_` in each word so they match literally.
3. **Query vector:** `ItemEmbedder::embed($term, timeout: 3)` inside try/catch; on any `Throwable`, `report()` it and continue with no vector.
4. **No vector:** filter by the keyword condition only.
5. **With a vector:** match items that satisfy the keyword condition **or** have a non-null embedding within cosine distance `1 - min_similarity` (`whereVectorDistanceLessThan`, which accepts an `'or'` boolean). Order first by "satisfies the keyword condition" (matches before non-matches; a `CASE` expression with the same bound patterns), then by `orderByVectorDistance` (Postgres sorts null embeddings last on ascending order).

Table: `$table->searchable()` (needed for the field to render) with `->searchUsing(fn (Builder $query, string $search) => $query->search($search))` and placeholder `Suchen …`. Filament applies the search before the default sort, so `created_at desc` remains only as the final tie-breaker.

- [ ] **Step 1: Write the failing tests.** `ItemSearchTest` `beforeEach`: `Queue::fake()`; helpers in the file:

```php
/** A 1024-dimension unit vector pointing along the given axis. */
function axis(int $index): array
{
    $vector = array_fill(0, 1024, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}

/** Creates an item with a fixed embedding (or none). */
function itemWith(array $attributes, ?array $embedding = null): Item
{
    $item = Item::factory()->create($attributes);
    $item->forceFill(['embedding' => $embedding])->saveQuietly();

    return $item;
}

/** Makes every query embed to the given vector. */
function queriesEmbedTo(array $vector): void
{
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn () => $vector, $prompt->inputs));
}
```

```php
test('a word matches as a case-insensitive substring of title or description', function () {
    queriesEmbedTo(axis(9));
    $inTitle = itemWith(['title' => 'Kunststoffgehäuse', 'description' => null]);
    $inDescription = itemWith(['title' => 'Box', 'description' => 'Ein GEHÄUSE aus Metall']);
    $other = itemWith(['title' => 'HDMI-Kabel']);

    expect(Item::query()->search('Gehäuse')->pluck('id')->all())
        ->toEqualCanonicalizing([$inTitle->id, $inDescription->id]);
});

test('every word must match', function () {
    queriesEmbedTo(axis(9));
    $both = itemWith(['title' => 'Raspberry Pi', 'description' => 'mit Gehäuse']);
    itemWith(['title' => 'Raspberry Pi', 'description' => 'ohne alles']);

    expect(Item::query()->search('  pi   gehäuse ')->pluck('id')->all())->toBe([$both->id]);
});

test('a query finds an item by meaning without sharing a word', function () {
    queriesEmbedTo(axis(1));
    $similar = itemWith(['title' => 'Pi-Gehäuse'], axis(1));
    itemWith(['title' => 'Gartenschlauch'], axis(2));

    expect(Item::query()->search('Hülle für Einplatinencomputer')->pluck('id')->all())->toBe([$similar->id]);
});

test('keyword matches come before semantic-only matches', function () {
    queriesEmbedTo(axis(1));
    $semantic = itemWith(['title' => 'Pi-Gehäuse'], axis(1));
    $keyword = itemWith(['title' => 'Hülle', 'description' => null], axis(2));
    $keywordWithoutEmbedding = itemWith(['title' => 'Schutzhülle', 'description' => null]);

    expect(Item::query()->search('Hülle')->pluck('id')->all())
        ->toBe([$keyword->id, $keywordWithoutEmbedding->id, $semantic->id]);
});

test('items below the similarity threshold are left out', function () {
    config(['ai.inventory.search_min_similarity' => 0.9]);
    $half = array_fill(0, 1024, 0.0);
    $half[1] = $half[2] = 1.0; // cosine similarity to axis(1) is about 0.71
    queriesEmbedTo(axis(1));
    itemWith(['title' => 'Irgendwas'], $half);

    expect(Item::query()->search('Suchbegriff')->count())->toBe(0);
});

test('search falls back to keywords and reports when embedding fails', function () {
    Exceptions::fake();
    Embeddings::fake(fn () => throw new RuntimeException('down'));
    $match = itemWith(['title' => 'HDMI-Kabel'], axis(1));
    itemWith(['title' => 'Gartenschlauch'], axis(1));

    expect(Item::query()->search('hdmi')->pluck('id')->all())->toBe([$match->id]);
    Exceptions::assertReported(RuntimeException::class);
});

test('search falls back to keywords when the provider returns another dimension', function () {
    Exceptions::fake();
    queriesEmbedTo(array_fill(0, 768, 0.1));
    $match = itemWith(['title' => 'HDMI-Kabel'], axis(1));

    expect(Item::query()->search('hdmi')->pluck('id')->all())->toBe([$match->id]);
});

test('percent, underscore and backslash are literal', function () {
    queriesEmbedTo(axis(9));
    $percent = itemWith(['title' => '100% Baumwolle']);
    itemWith(['title' => '100 Schrauben']);

    expect(Item::query()->search('100%')->pluck('id')->all())->toBe([$percent->id])
        ->and(Item::query()->search('_')->count())->toBe(0)
        ->and(Item::query()->search('\\')->count())->toBe(0);
});

test('a blank term returns everything and embeds nothing', function () {
    Embeddings::fake();
    itemWith(['title' => 'A']);
    itemWith(['title' => 'B']);

    expect(Item::query()->search('   ')->count())->toBe(2);
    Embeddings::assertNothingGenerated();
});
```

Panel test in `ItemResourceTest`:

```php
test('the search field above the list finds items by keyword and by meaning', function () {
    $keyword = Item::factory()->create(['title' => 'Kunststoffgehäuse']);
    $semantic = Item::factory()->create(['title' => 'Pi-Hülle']);
    $semantic->forceFill(['embedding' => axisVector(1)])->saveQuietly(); // same unit-vector helper, local to this file
    $other = Item::factory()->create(['title' => 'Gartenschlauch']);
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn () => axisVector(1), $prompt->inputs));

    Livewire::test(ListItems::class)
        ->searchTable('Gehäuse')
        ->assertCanSeeTableRecords([$keyword, $semantic], inOrder: true)
        ->assertCanNotSeeTableRecords([$other]);
});
```

- [ ] **Step 2: Run and see them fail** — `docker compose exec app php artisan test --compact tests/Feature/Models/ItemSearchTest.php tests/Feature/Panel/ItemResourceTest.php`.
- [ ] **Step 3: Implement** the scope and wire it into `ItemsTable`.
- [ ] **Step 4: Run the tests** — same command; expected PASS. Then the whole suite.
- [ ] **Step 5: Commit** (`Search items by keyword and by meaning`).

---

### Task 9: Documentation and live check

**Files:**
- Modify: `CLAUDE.md` (project section only, not the Boost block), `README.md`

- [ ] **Step 1: Update `CLAUDE.md`:** architecture bullets for storage locations (number = id, `/lagerorte/{id}` is the future QR target), items (created only via "Übernehmen", photo ownership and the `deleteQuietly()` rule), search (`Item::search`, keyword first, fallback), embeddings (`ItemEmbedder`, `EmbedItem`, `items:embed` hourly, env keys, `ollama pull bge-m3`, dimension change needs a migration and `items:embed --all`), the `EmbedItem` timeout in the queue timing invariant, and `Embeddings::fake()` in `TestCase`.
- [ ] **Step 2: Update `README.md`** "Where things stand": what is built now; next: label and QR printing.
- [ ] **Step 3: Verify everything**

Run: `docker compose exec app php artisan test --compact && docker compose exec app ./vendor/bin/pint --dirty --format agent && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 4: Live check (needs the user).** Prerequisite: the user has run `ollama pull bge-m3`. With the user's go-ahead for converting real imports, walk through the spec's Acceptance list 1–7 against http://localhost:8000 and tune `INVENTORY_SEARCH_MIN_SIMILARITY` if unrelated items show up or related ones are missing. Report what was checked and what was not.
- [ ] **Step 5: Commit** (`Document storage locations, items and search`).
