# Spots and locations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Today's "Lagerort" becomes the Lagerplatz (`Spot`), a new Lagerort (`Location`) groups spots, and all URL slugs become short English words.

**Architecture:** A mechanical rename comes first and changes no behaviour. Slugs follow. Then `Location` is added as a UUID model with a mandatory `spots.location_id`, a single modal-based Filament page, a grouped server-searched spot picker, and `full_label` plus a filter for display.

**Tech Stack:** Laravel 13, Filament 5, Pest, Postgres 18.

**Spec:** `docs/superpowers/specs/2026-11-06-spots-and-locations-design.md`. Vocabulary: `CONTEXT.md`.

## Global Constraints

- Everything PHP runs in Docker: `docker compose exec app …`. Never use host PHP/Composer.
- Work on a new branch `spots-and-locations`, not on `master`.
- UI copy is German: "Lagerplatz"/"Lagerplätze" for `Spot`, "Lagerort"/"Lagerorte" for `Location`. Slugs, classes, tables and columns are English.
- Never run `migrate:fresh` or anything else that wipes `tory`; the stored providers must survive. Normal `php artisan migrate` is fine.
- Migrations that have already run stay unchanged; every schema change is a new migration.
- Failing test first, except in the purely mechanical Tasks 1 and 2, where the renamed existing tests are the check. Never assert a literal id.
- Use `make:` generators with `--no-interaction` for new files. Activate the `filament-development` skill and use Boost's `search-docs` before Filament API use.
- Before each commit: `docker compose exec app ./vendor/bin/pint --dirty --format agent`. Before finishing: `docker compose exec app ./vendor/bin/phpstan analyse`.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

- A picker search for `%` or `_` must not match every spot. Task 4.
- The item filter combined with a text search must narrow results, not widen them. Task 5.
- Moving a spot to another location must keep its code and show its items under the new location. Task 3.
- Deleting a location that has spots must fail even when the disabled button is bypassed. Task 3.
- The backfill must work with existing spots and also on an installation without any. Task 3.

---

### Task 1: Rename `StorageLocation` to `Spot`

No behaviour changes except the copy and the slug.

**Files:**
- Create: `database/migrations/*_rename_storage_locations_to_spots.php`, `tests/Feature/Migrations/RenameStorageLocationsToSpotsTest.php`
- Move with `git mv`: `app/Models/StorageLocation.php` → `Spot.php`; `app/Filament/Resources/StorageLocations/` → `Spots/` with `StorageLocationResource`, `StorageLocationForm`, `StorageLocationInfolist`, `StorageLocationSelect`, `StorageLocationsTable` and the four pages renamed to `Spot…`; `database/factories/StorageLocationFactory.php` → `SpotFactory.php`; `tests/Feature/Models/StorageLocationTest.php` → `SpotTest.php`; `tests/Feature/Panel/StorageLocationResourceTest.php` → `SpotResourceTest.php`
- Modify: `app/Models/{Item,Import,User}.php`, `app/Filament/Resources/{Imports,Items}/**`, `database/factories/ItemFactory.php`, all tests from `grep -rlE 'StorageLocation|storage_location|storageLocation|lagerorte' tests`, `CLAUDE.md`, `README.md`

**Interfaces:**
- Produces: `App\Models\Spot` (with `CODE_ALPHABET`, `CODE_LENGTH`, `label`, `items()`, `imports()`); `Item::spot()`, `Import::spot()`; columns `items.spot_id`, `imports.spot_id`, `users.last_spot_id`; `Import::convertToItem(string $title, ?string $description, int $spotId, array $searchTerms = [])`; `App\Filament\Resources\Spots\SpotResource` with slug `spots`; `SpotSelect::make(string $name = 'spot_id')`; `SpotForm::components()`.

- [ ] **Step 1: Create the branch**

Run: `git switch -c spots-and-locations`

- [ ] **Step 2: Write the failing migration test**

`tests/Feature/Migrations/RenameStorageLocationsToSpotsTest.php`, test `'renaming keeps spots and the items on them'`: `require` the migration file via `glob(database_path('migrations/*_rename_storage_locations_to_spots.php'))[0]`, call `down()`, insert one row into `storage_locations` and one into `items` with `DB::table`, call `up()`, then assert that `DB::table('spots')` has the same id, code and name and that the item's `spot_id` equals that id.

- [ ] **Step 3: Run it to verify it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/Migrations/RenameStorageLocationsToSpotsTest.php`
Expected: FAIL because the migration file does not exist.

- [ ] **Step 4: Write the migration**

`up()` renames the table, the three columns, and with raw SQL the constraints and sequence below; `down()` reverses all of it.

| Old | New |
|---|---|
| `storage_locations_pkey` | `spots_pkey` |
| `storage_locations_code_unique` | `spots_code_unique` |
| `items_storage_location_id_foreign` | `items_spot_id_foreign` |
| `imports_storage_location_id_foreign` | `imports_spot_id_foreign` |
| `users_last_storage_location_id_foreign` | `users_last_spot_id_foreign` |
| sequence `storage_locations_id_seq` | `spots_id_seq` |

- [ ] **Step 5: Rename the code**

Apply the moves above and rename across `app`, `database/factories` and `tests`: `StorageLocation` → `Spot`, `storageLocation` → `spot`, `storage_location_id` → `spot_id`, `last_storage_location_id` → `last_spot_id`, `$location`/`$locationId` referring to a spot → `$spot`/`$spotId`. Set `$slug = 'spots'`, `$modelLabel = 'Lagerplatz'`, `$pluralModelLabel` and `$navigationLabel = 'Lagerplätze'`. Change every `'Lagerort'` copy string to `'Lagerplatz'`, and `/lagerorte` to `/spots` in tests. In `PrimaryKeysTest` the test becomes `'spots keep their internal numeric key'`.

- [ ] **Step 6: Verify nothing is left**

Run: `grep -rnEi 'storage.?location|lagerort' app database/factories tests/Feature tests/Unit routes resources`
Expected: only `tests/Feature/Migrations/RenameStorageLocationsToSpotsTest.php`.

- [ ] **Step 7: Update the docs**

Rename the concept in `CLAUDE.md` ("Storage locations" section, `/spots/{code}`, `SpotSelect`, `last_spot_id`, the primary key convention) and add under the heading `# Tory` the line "Vocabulary lives in `CONTEXT.md`.". In `README.md` also correct the outdated "Nr. 12" description of the code.

- [ ] **Step 8: Run all tests and static analysis**

Run: `docker compose exec app php artisan test --compact && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 9: Migrate the development database and restart the worker**

Run: `docker compose exec app php artisan migrate && docker compose exec app supervisorctl restart queue`, then `docker compose exec db psql -U tory -d tory -c 'select code, name from spots'`.
Expected: `4WWH Schrank` and `BNDU Test`, if the user has not changed them since.

- [ ] **Step 10: Commit**

Message: `Rename storage locations to spots`.

---

### Task 2: English slugs

**Files:**
- Modify: `app/Filament/Resources/Items/ItemResource.php`, `Imports/ImportResource.php`, `AiProviders/AiProviderResource.php`, `tests/Feature/Panel/{RoutesTest,ListImportsTest,CreateImportTest}.php`, any other test from `grep -rlE 'gegenstaende|/importe|eigene-ki' tests`, `CLAUDE.md`, `README.md`

**Interfaces:**
- Produces: slugs `items`, `imports`, `ai-providers`.

- [ ] **Step 1: Write the failing test**

In `RoutesTest.php`, test `'old german addresses are gone'` with dataset `/lagerorte`, `/gegenstaende`, `/importe`, `/eigene-ki`: a signed-in user gets `assertNotFound()`. Change the existing `/gegenstaende` and `/importe` paths in the tests to `/items` and `/imports`, add `'/ai-providers'` to a test that asserts `assertOk()`.

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/RoutesTest.php`
Expected: FAIL on the old addresses.

- [ ] **Step 3: Change the three `$slug` values and the addresses in `CLAUDE.md` and `README.md`**

- [ ] **Step 4: Run all tests**

Run: `docker compose exec app php artisan test --compact`
Expected: all green.

- [ ] **Step 5: Commit**

Message: `Use English URL slugs`.

---

### Task 3: `Location` model, backfill and Lagerorte page

**Files:**
- Create: `app/Models/Location.php`, `database/factories/LocationFactory.php`, `database/migrations/*_create_locations_table.php`, `app/Filament/Resources/Locations/LocationResource.php`, `Locations/Schemas/LocationForm.php`, `Locations/Tables/LocationsTable.php`, `Locations/Pages/ManageLocations.php`, `tests/Feature/Models/LocationTest.php`, `tests/Feature/Panel/LocationResourceTest.php`, `tests/Feature/Migrations/CreateLocationsTest.php`
- Modify: `app/Models/Spot.php`, `database/factories/SpotFactory.php`, `app/Filament/Resources/Spots/Schemas/{SpotForm,SpotInfolist}.php`, `Spots/Tables/SpotsTable.php`, `Spots/SpotResource.php`, `Imports/ImportResource.php`, `AiProviders/AiProviderResource.php`, `tests/Feature/Models/PrimaryKeysTest.php`, `tests/Feature/Panel/SpotResourceTest.php`

**Interfaces:**
- Consumes: `Spot`, `SpotForm::components()` from Task 1.
- Produces: `Location` (`HasUuids`, fillable `name`, `spots(): HasMany`); `Spot::location(): BelongsTo`, fillable `location_id`; `LocationResource` with slug `locations`; `LocationForm::components(): array<Component>`; `SpotFactory` creating a location by default.

- [ ] **Step 1: Write the failing migration tests**

`CreateLocationsTest.php`, `require` the migration as in Task 1:
- `'existing spots move to a location named Allgemein'`: `down()`, insert two spots with `DB::table`, `up()`; exactly one row in `locations` with name `Allgemein`, and both spots have its id as `location_id`.
- `'an installation without spots gets no location'`: `down()`, `up()`; `locations` is empty.

- [ ] **Step 2: Write the failing model and panel tests**

`LocationTest.php`:
- `'a location with spots cannot be deleted'`: `expect(fn () => $spot->location->delete())->toThrow(QueryException::class)`.

`LocationResourceTest.php`, using `ManageLocations`:
- `'lists locations with their number of spots'`, asserting `assertCanSeeTableRecords` and `/locations` answering OK.
- `'creates a location'` and `'renames a location'` via `callAction`/`callTableAction`.
- `'rejects a name that already exists'`: `assertHasFormErrors(['name' => 'unique'])`; renaming a location while keeping its own name passes.
- `'delete is disabled while spots belong to the location'` with `assertTableActionDisabled('delete', $location)`, and `'deletes an empty location'`.

`SpotResourceTest.php`:
- `'a spot requires a location'`: `assertHasFormErrors(['location_id' => 'required'])`.
- `'the only location is preselected'`: one location gives `assertFormSet(['location_id' => $location->getKey()])` on `CreateSpot`; with two, `location_id` is null.
- `'moving a spot keeps its code and its items'`: on `EditSpot` set a new `location_id` and save; `code` is unchanged, and `$item->fresh()->spot->location` is the new location.
- `'a location can be created from the spot form'`: use the `createOption` action of `location_id` with `['name' => 'Keller']`; a `Location` named Keller exists and `location_id` holds its key.

In `PrimaryKeysTest`, add `Location::class` to the dataset of `'new records get a uuid as primary key'`.

- [ ] **Step 3: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Migrations/CreateLocationsTest.php tests/Feature/Models/LocationTest.php tests/Feature/Panel/LocationResourceTest.php`
Expected: FAIL because the class and migration are missing.

- [ ] **Step 4: Write the migration, model and factory**

`up()`: create `locations` (uuid primary key, unique `name`, timestamps); add `spots.location_id` as nullable uuid; if `spots` has rows, insert `Allgemein` with `Str::uuid7()` and assign all spots; make the column NOT NULL and add the foreign key with restrict on delete plus an index. `down()` drops the column and the table.

`SpotFactory::definition()` gets `'location_id' => Location::factory()`.

- [ ] **Step 5: Write the Lagerorte page**

Follow `AiProviderResource` for the one-page structure: `index => ManageLocations::route('/')`. `$slug = 'locations'`, labels "Lagerort"/"Lagerorte", `Heroicon::OutlinedMapPin`.

- `LocationForm::components()`: `TextInput::make('name')->label('Name')->required()->maxLength(255)->unique(ignoreRecord: true)`.
- `LocationsTable`: columns name (searchable) and `spots_count` labelled "Lagerplätze" via `->counts('spots')`; `defaultSort('name')`; `EditAction`; `DeleteAction` with `->disabled(fn (Location $record): bool => $record->spots()->exists())` and the tooltip "Enthält noch Lagerplätze" while disabled.
- `$navigationSort`: `SpotResource` stays 2, `LocationResource` 3, `ImportResource` 4, `AiProviderResource` 5.

- [ ] **Step 6: Add the location to spots**

- `SpotForm::components()` first gets `Select::make('location_id')`, label "Lagerort", `required()`, `searchable()`, options as id → name sorted by name, `exists(Location::class, 'id')`, `createOptionForm(LocationForm::components())`, `createOptionUsing(fn (array $data): string => Location::create($data)->getKey())`, and a `default()` closure that returns the id only when exactly one location exists.
- `SpotsTable` gets `TextColumn::make('location.name')` labelled "Lagerort", with `->with('location')` through `modifyQueryUsing`.
- `SpotInfolist` gets `TextEntry::make('location.name')` labelled "Lagerort".

- [ ] **Step 7: Run all tests**

Run: `docker compose exec app php artisan test --compact`
Expected: all green. Fix tests that create spots with `DB::table` or without the factory.

- [ ] **Step 8: Migrate and verify the development database**

Run: `docker compose exec app php artisan migrate`, then `docker compose exec db psql -U tory -d tory -c 'select s.code, l.name from spots s join locations l on l.id = s.location_id'`.
Expected: every spot on `Allgemein`.

- [ ] **Step 9: Commit**

Message: `Add locations that group spots`.

---

### Task 4: Grouped, searchable spot picker

**Files:**
- Modify: `app/Filament/Resources/Spots/Schemas/SpotSelect.php`, `tests/Feature/Panel/SpotResourceTest.php`

**Interfaces:**
- Consumes: `Spot::location()`, `Location` from Task 3.
- Produces: `SpotSelect::options(?string $search = null): array<string, array<int, string>>`, location name → spot id → `label`.

- [ ] **Step 1: Write the failing tests**

- `'groups spots by location, sorted by name'`: with Garage/Werkbank and Keller/Regal and Keller/Kiste, `SpotSelect::options()` equals `['Garage' => [werkbank id => label], 'Keller' => [kiste id => label, regal id => label]]`, and `array_keys` keep this order.
- `'finds spots by code, spot name or location name'`, dataset of the code in lower case, `regal`, `keller`: each finds the expected spot, and no others.
- `'every word has to match'`: `keller regal` finds only Regal in Keller, `keller werkbank` finds nothing.
- `'percent and underscore are searched literally'`: dataset `%`, `_`, gives `[]` when no name contains them.
- `'omits locations without spots'`.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/SpotResourceTest.php --filter='groups|finds|every word|percent|omits'`
Expected: FAIL because `options` does not exist.

- [ ] **Step 3: Implement**

`SpotSelect::options()` joins `locations`, orders by location name, spot name, code, and for each whitespace-separated word adds a grouped condition `spots.code ilike ? or spots.name ilike ? or locations.name ilike ?`, escaping like `ItemSearch::keywordCondition()`. `make()` uses `->options(fn (): array => self::options())`, `->getSearchResultsUsing(fn (string $search): array => self::options($search))`, `->getOptionLabelUsing(fn (mixed $value): ?string => Spot::find($value)?->label)`. The label stays "Lagerplatz"; `exists`, `createOptionForm` and `createOptionUsing` stay.

- [ ] **Step 4: Run all tests**

Run: `docker compose exec app php artisan test --compact`
Expected: all green, including the existing tests for the import form and "Übernehmen".

- [ ] **Step 5: Commit**

Message: `Group the spot picker by location and search both names`.

---

### Task 5: Show locations with spots and filter items

**Files:**
- Modify: `app/Models/Spot.php`, `app/Filament/Resources/Items/Tables/ItemsTable.php`, `Items/Schemas/ItemInfolist.php`, `Imports/Tables/ImportsTable.php`, `Imports/Schemas/ImportInfolist.php`, `tests/Feature/Models/SpotTest.php`, `tests/Feature/Panel/{ItemResourceTest,ViewImportTest,ListImportsTest}.php`, `CLAUDE.md`, `README.md`

**Interfaces:**
- Consumes: `Spot::location()`, `Location` from Task 3.
- Produces: `Spot::$full_label`, `{location name} → {code} · {name}`.

- [ ] **Step 1: Write the failing tests**

- `SpotTest`, `'the full label names the location'`: Keller and Regal give `"Keller → {$spot->code} · Regal"`.
- `ItemResourceTest`, `'the list and view page show the location'`: both pages `assertSee('Keller → ')` and still link to `/spots/{code}`.
- `ItemResourceTest`, `'filters items by location'`: `filterTable('location', $keller->getKey())` shows the item in Keller and not the one in Garage.
- `ItemResourceTest`, `'the location filter narrows a text search'`: two items with the same title in different locations; `searchTable(title)` plus the filter shows one. Include an unrelated item in the chosen location, which must stay hidden.
- `ViewImportTest` and `ListImportsTest`: an import with a spot shows `Keller → `; one without a spot still renders.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/SpotTest.php tests/Feature/Panel/ItemResourceTest.php`
Expected: FAIL on the new tests.

- [ ] **Step 3: Implement**

- `Spot::fullLabel()` as an `Attribute`, next to `label()`.
- `ItemsTable` and `ItemInfolist`: `spot.full_label`, the URL stays. `ImportsTable` and `ImportInfolist`: `spot.full_label`.
- Eager load `spot.location` in `ItemsTable::modifyQueryUsing` and likewise in `ImportsTable`.
- `ItemsTable->filters([...])`: `SelectFilter::make('location')`, label "Lagerort", options as id → name sorted by name, and a `query()` closure that applies `whereHas('spot', fn ($spot) => $spot->where('location_id', $value))` only when a value is set.

- [ ] **Step 4: Run all tests and static analysis**

Run: `docker compose exec app php artisan test --compact && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 5: Document the concept**

In `CLAUDE.md`, add to the architecture list a `Locations` entry (grouping only, unique name, UUID, no code, no detail page, `/locations`, restrict on delete, "Allgemein" from the backfill), and to the spots entry `location_id`, `full_label` and the server-side picker search; set the navigation order to Gegenstände, Lagerplätze, Lagerorte, Importe, Eigene KI. In `README.md`, add the concept to the feature list.

- [ ] **Step 6: Commit**

Message: `Show locations with spots and filter items by location`.

- [ ] **Step 7: Ask the user for a manual check**

Browser tools cannot reach localhost in this setup, and tests do not run Filament's JavaScript. Ask the user to check on `/imports/create`: the picker opens with groups; typing a location name shows that location's spots; the last spot is preselected and its label appears; creating a spot through the picker's plus button selects the new spot with its label.
