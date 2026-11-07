# Bulk Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upload up to 100 photos at once, group them by thing (capture time, then an AI check of neighbouring photos), let the user correct the groups on a review page, and create one normal import per group on confirm. Raise the photo limit of imports and items from 6 to 20.

**Architecture:** A `BulkImport` model holds the photos in time order, the time-based and the current cut points between neighbouring photos, and, once grouping is done, the groups as cards. `GroupBulkImport` walks overlapping windows of up to 8 photos through a new structured-output agent and overwrites cut points; it resumes itself with a cursor like `ReembedItems`. All review edits and the confirm are model methods that lock the row and apply the change to its current state, like `Item::changePhotos()`. The panel adds a hidden resource with an upload page (Alpine field reading EXIF with `exifr` before the canvas resize) and a review page (cards, `wire:sort` between cards).

**Tech Stack:** Laravel 13, Filament 5, Livewire 4 (`wire:sort`), Alpine, `laravel/ai` structured-output agent, `exifr` (npm, approved), plain CSS, Pest.

**Spec:** `docs/superpowers/specs/2026-11-07-bulk-import-design.md`

## Global Constraints

- Branch `feature/TOR-10-bulk-import`; do not push. End commit messages with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Everything PHP runs in `docker compose exec app …`, npm in `docker compose exec vite npm …`. Run `./vendor/bin/pint --dirty --format agent` and `./vendor/bin/phpstan analyse` before each commit.
- Only new dependency: npm `exifr`, pinned to an exact version (`7.1.3`). No PHP dependency, no `.env` or Docker image change.
- UI copy is German; light and dark mode; plain CSS (no uncompiled Tailwind classes in the panel), loaded through the `HEAD_END` `@vite` render hook and listed in `vite.config.js` `input`.
- At most 100 photos per bulk import; 1 to 20 photos per import and item, everywhere.
- JPEG, PNG, WebP or GIF, at most 12 MB per file, shrunk in the browser to fit 2048 × 2048 px, never enlarged, GIFs untouched; the server checks type and size again.
- Time gap: more than 30 seconds between neighbouring photos starts a new group.
- AI windows: at most 8 photos per call; every neighbouring pair decided by exactly one window.
- Job timing: `$tries = 1`, `$timeout = 200`, budget 150 s plus one client call of at most 45 s. Keep every job timeout < 210 < 300.
- Photos live on the private `local` disk under `imports/`; a file belongs to exactly one bulk import, import or item. Confirm hands files over without copying; files are deleted only after commit.
- UUID primary key (`HasUuids`), status enums implement `HasLabel`/`HasColor`, DTOs over arrays (arrays only in casts/JSON columns), constructor injection or typed closure parameters, no `app()`/`resolve()`.
- Resource pages use `StartsBreadcrumbsAtHome`; tests follow `testing-best-practices`, `Storage::fake('local')`, `Queue::fake()` unless the job is under test, never assert a literal bigint id.

## Review Focus

- A photo with no capture time at all among timed ones (a screenshot): it sorts after all timed photos by file name and no time cut is made next to it, so only the AI decides that pair (test in Task 2).
- The bulk import is deleted while `GroupBulkImport` is queued or running: the job ends quietly, no file is recreated and nothing is reported (test in Task 4).
- A stale second tab sends an edit for a card that no longer exists (merged or split meanwhile): nothing changes, the user gets a German notice and the page shows the current cards (tests in Tasks 6 and 7).
- "Importe anlegen" is pressed twice or in two tabs: the second answers 404 and creates nothing (test in Task 6).
- A forged upload path or forged review path that is not part of this bulk import: rejected, no file touched (tests in Tasks 5 and 6).

---

### Task 1: Raise the photo limit to 20 and cap API upload size

**Files:**
- Modify: `app/Models/Import.php` (constant), `app/Filament/Resources/Imports/Schemas/ImportForm.php`, `app/Http/Requests/Api/V1/StoreImportRequest.php`, `app/Models/Item.php` (`changePhotos`), `resources/js/item-photos.js`, `CONTEXT.md` (Import: "1–20 photos"), `CLAUDE.md` (every "1–6"/"6 photos"/"1 to 6" mention)
- Test: `tests/Feature/ItemPhotoChangesTest.php`, `tests/Feature/Api/ImportsTest.php`, `tests/Feature/Panel/CreateImportTest.php` (adapt every test that asserts 6; do not weaken)

**Interfaces:**
- Produces: `Import::MAX_PHOTOS = 20` (`public const int`), used by every server-side limit in this plan. `StoreImportRequest::MAX_TOTAL_KILOBYTES = 71680` (70 MB).

- [ ] **Step 1: Adapt and add the failing tests**

```php
// ItemPhotoChangesTest: 'more than six photos …' becomes
test('more than twenty photos are rejected and nothing changes'); // 20 + 1 upload → ValidationException on data.photo_edits "Höchstens 20 Fotos."; no file stored
test('twenty photos are accepted');                               // 19 + 1 upload → 20 photos
// Api/ImportsTest
test('an import accepts twenty photos');                          // 20 × UploadedFile::fake()->image('p.jpg') → 201
test('an import rejects twenty-one photos');                      // → 422 on photos
test('an import rejects photos over 70 MB in total');             // 6 × UploadedFile::fake()->create('p.jpg', 12000, 'image/jpeg') → 422, errors.photos === ['Alle Fotos zusammen dürfen höchstens 70 MB groß sein.'], no Import, no file
// CreateImportTest
test('erfassen accepts twenty photos and rejects twenty-one');
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact --filter='twenty|70 MB'`
Expected: FAIL (limit is still 6, no total rule).

- [ ] **Step 3: Implement**

Replace every literal 6 with `Import::MAX_PHOTOS` (`maxFiles`, `'max:'.Import::MAX_PHOTOS`, `changePhotos` check and its message `'Höchstens '.Import::MAX_PHOTOS.' Fotos.'`). In `item-photos.js` set `MAX_PHOTOS = 20` and make its message `'Höchstens 20 Fotos.'`. Add a closure rule on `photos` in `StoreImportRequest` that sums `getSize()` of the uploaded files and fails with the message above when the sum exceeds `MAX_TOTAL_KILOBYTES * 1024`. Update `CONTEXT.md` and `CLAUDE.md`.

- [ ] **Step 4: Run the affected files**

Run: `docker compose exec app php artisan test --compact tests/Feature/ItemPhotoChangesTest.php tests/Feature/Api/ImportsTest.php tests/Feature/Panel/CreateImportTest.php tests/Feature/Panel/ItemResourceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit** — `Raise the photo limit to 20 and cap API upload size (TOR-10)`

---

### Task 2: BulkImport model, time grouping and start

**Files:**
- Create: migration `create_bulk_imports_table`, `app/Models/BulkImport.php`, `database/factories/BulkImportFactory.php`, `app/Enums/BulkImportStatus.php`, `app/Enums/GroupMark.php`, `app/BulkImports/BulkPhoto.php`, `app/BulkImports/StagedPhoto.php`, `app/BulkImports/PhotoGroup.php`, `app/BulkImports/TimeGrouping.php`
- Test: `tests/Feature/Models/BulkImportTest.php`, `tests/Unit/TimeGroupingTest.php`

`app/BulkImports/` is a new folder under `app/` for the feature's DTOs and pure helpers (not a new base folder).

**Interfaces:**
- Produces:
  - Table `bulk_imports`: `id` uuid, `user_id` (FK users, cascade), `spot_id` (FK spots, restrict), `status` string, `photos` jsonb (list of `{path, taken_at: ISO-8601|null, name}` in time order), `time_cuts` jsonb and `cuts` jsonb (both `list<bool>`, length = photos − 1; `true` at index *i* = a new thing begins between photo *i* and *i+1*), `groups` jsonb nullable (list of `{id, photos: list<path>, mark: 'merged'|'split'|null}`), `discarded` jsonb (list of paths, default `[]`), `grouping_incomplete` bool default false, `failure_reason` nullable string (`ProviderFailureReason`), `failure_provider` nullable string, timestamps.
  - `enum BulkImportStatus: string` — `Grouping = 'grouping'` ("Wird gruppiert", `warning`), `Ready = 'ready'` ("Bereit zur Prüfung", `info`).
  - `enum GroupMark: string implements HasLabel` — `Merged = 'merged'` ("KI: zusammengelegt"), `Split = 'split'` ("KI: aufgeteilt").
  - `final readonly class BulkPhoto(string $path, ?CarbonImmutable $takenAt, string $name)`
  - `final readonly class StagedPhoto(UploadedFile $file, string $name, ?CarbonImmutable $takenAt)`
  - `final readonly class PhotoGroup(string $id, list<string> $photos, ?GroupMark $mark)`
  - `TimeGrouping::GAP_SECONDS = 30`; `TimeGrouping::sort(list<BulkPhoto>): list<BulkPhoto>`; `TimeGrouping::cuts(list<BulkPhoto>): list<bool>`.
  - `BulkImport::start(User $user, int $spotId, list<StagedPhoto> $photos): BulkImport` — stores files under `imports/`, sorts, sets `time_cuts` = `cuts` = `TimeGrouping::cuts()`, status `Grouping`, and dispatches `GroupBulkImport` after commit (the job class comes in Task 4; create an empty `GroupBulkImport` job now so the dispatch compiles). Throws `ValidationException` (key `data.photos`) for 0 or more than 100 photos. On a failure removes files already stored.
  - `BulkImport::MAX_PHOTOS = 100`.
  - `BulkImport::photoList(): list<BulkPhoto>`, `BulkImport::groupList(): list<PhotoGroup>` (empty while grouping), `BulkImport::allPaths(): list<string>`, `BulkImport::failureMessage(): ?string` (as on `Import`).
  - `deleted` model event deletes every path of `allPaths()`.

- [ ] **Step 1: Write the failing tests**

```php
// Unit/TimeGroupingTest
test('photos sort by capture time, then by name');
test('photos without a time come after timed photos, sorted by name');
test('a gap of more than 30 seconds cuts, exactly 30 does not');   // 10:00:00, 10:00:30, 10:01:01 → [false, true]
test('no cut is made next to a photo without a time');             // timed, untimed → [false]
test('a single photo has no cuts');                                // → []
// Feature/Models/BulkImportTest
test('start stores the photos, sorts them and sets time cuts');    // files exist under imports/, status Grouping, cuts === time_cuts
test('start dispatches the grouping job after commit');            // Queue::fake(); Queue::assertPushed(GroupBulkImport::class)
test('start rejects more than 100 photos and stores nothing');     // 101 → ValidationException 'Höchstens 100 Fotos pro Bulkimport.'
test('deleting a bulk import deletes all its photo files');        // incl. discarded
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Unit/TimeGroupingTest.php tests/Feature/Models/BulkImportTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Implement** the migration (`php artisan make:migration … --no-interaction`), enums (move generated files to `app/Enums/`), DTOs, `TimeGrouping`, the model with casts (`photos`, `time_cuts`, `cuts`, `groups`, `discarded` as `array`; `status`, `failure_reason` as enums), `start()`, the `deleted` hook, and a factory with states `grouping()` and `ready(array $groups)` that writes real fake files for its photos.

- [ ] **Step 4: Run the tests** (same command) — Expected: PASS.

- [ ] **Step 5: Commit** — `Add the bulk import model and time grouping (TOR-10)`

---

### Task 3: Boundary windows and the AI checker

**Files:**
- Create: `app/BulkImports/BoundaryWindow.php`, `app/BulkImports/BoundaryWindows.php`, `app/Ai/Agents/PhotoBoundaryChecker.php`
- Test: `tests/Unit/BoundaryWindowsTest.php`, `tests/Feature/Ai/PhotoBoundaryCheckerTest.php`

**Interfaces:**
- Produces:
  - `final readonly class BoundaryWindow(int $firstPhoto, int $lastPhoto, int $firstPair, int $lastPair)` (all inclusive; pair *p* lies between photo *p* and *p+1*).
  - `BoundaryWindows::SIZE = 8`; `BoundaryWindows::for(int $photoCount): list<BoundaryWindow>`.
  - `final class PhotoBoundaryChecker implements Agent, HasStructuredOutput` with `__construct(public ?AiProvider $aiProvider = null)`, provider/model selection exactly like `InventoryExtractor` (active provider, else `config('ai.inventory.*')`), and `check(array $attachments, int $timeout): list<bool>` returning one bool per neighbouring pair of the given photos (`true` = a new thing begins). Throws `RuntimeException('Das Modell hat keine vollständige Antwort geliefert.')` when the answer is not a list of exactly `count($attachments) - 1` booleans.

Window algorithm (not determined by the signature):

```php
// pairs = photoCount - 1; next = first undecided pair
while ($next < $pairs) {
    $first = max(0, $next - 1);                       // two photos of left context where possible
    $last = min($first + self::SIZE - 1, $photoCount - 1);
    $lastPair = $last === $photoCount - 1 ? $pairs - 1 : $last - 2; // keep two photos of right context unless at the end
    $windows[] = new BoundaryWindow($first, $last, $next, $lastPair);
    $next = $lastPair + 1;
}
```

- [ ] **Step 1: Write the failing tests**

```php
test('fewer than two photos need no window');                       // for(0), for(1) → []
test('up to eight photos fit one window deciding every pair');      // for(8) → [BoundaryWindow(0, 7, 0, 6)]
test('every pair is decided by exactly one window');                // for(n) for n in 2..100: decided pairs are 0..n-2, no overlap, no gap
test('no window holds more than eight photos');
test('a hundred photos need at most twenty windows');
// Feature/Ai/PhotoBoundaryCheckerTest
test('returns the boundaries the model answered');                  // fake([['boundaries' => [false, true]]]) with 3 images → [false, true]
test('an answer of the wrong length is incomplete');                // 3 images, [true] → RuntimeException
test('non-boolean entries are incomplete');
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Unit/BoundaryWindowsTest.php tests/Feature/Ai/PhotoBoundaryCheckerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement.** Schema: `boundaries` = required array of booleans. German instructions: the photos come in capture order and belong to a household inventory; for every pair of neighbouring photos answer whether the second shows a different thing than the first; the same thing from another angle, a close-up of its label or its packaging counts as the same thing; answer with exactly *n − 1* values, as a bare JSON object (same bare-JSON sentence as `InventoryExtractor`, because `gemma4` ignores the format schema). Put the photo count into the prompt text.

- [ ] **Step 4: Run the tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `Add boundary windows and the photo boundary checker (TOR-10)`

---

### Task 4: GroupBulkImport job

**Files:**
- Modify: `app/Jobs/GroupBulkImport.php` (from Task 2), `app/Models/BulkImport.php`
- Test: `tests/Feature/Jobs/GroupBulkImportTest.php`

**Interfaces:**
- Consumes: `BoundaryWindows::for()`, `PhotoBoundaryChecker::check()`, `PhotoAttachments::fromStorage()`, `ProviderFailure::from()`, `AiProvider::active()`.
- Produces:
  - `GroupBulkImport(BulkImport $bulkImport, int $nextPair = 0)`, `$tries = 1`, `$timeout = 200`, `$deleteWhenMissingModels = true`, `BUDGET_SECONDS = 150`, `CLIENT_TIMEOUT = 45`.
  - `BulkImport::storeCuts(int $firstPair, list<bool> $decided): void` — writes the decided cuts into `cuts` under a row lock; no-op when the row is gone or not `Grouping`.
  - `BulkImport::finishGrouping(bool $incomplete, ?ProviderFailure $failure = null): void` — builds `groups` from `cuts` (new `(string) Str::uuid7()` id per group), sets each mark (`Merged` when the group spans a `time_cuts` cut that `cuts` removed; otherwise `Split` when one of its edges is a cut that `time_cuts` did not have; otherwise null), stores `grouping_incomplete`, `failure_reason`, `failure_provider`, status `Ready`. No-op when not `Grouping`.

Behaviour of `handle()`: skip unless `Grouping`; read `AiProvider::active()` once; for each window from the one containing `nextPair`: stop and `self::dispatch($bulkImport, $window->firstPair)` once 150 s have passed; send the window's photos with `CLIENT_TIMEOUT`; `storeCuts()` the window's pairs (`firstPair..lastPair`, sliced from the answer). On a throwable: wrap in `ProviderFailure` when a provider was active, `report()` it, and `finishGrouping(true, $failure)`. After the last window: `finishGrouping(false)`. `failed()` calls `finishGrouping(true)`.

- [ ] **Step 1: Write the failing tests** (`PhotoBoundaryChecker::fake(...)`, factory `grouping()` with real fake files)

```php
test('declares one attempt, a 200 second timeout and deletes when the model is missing');
test('the AI answers replace the time cuts and the groups follow');           // 3 photos, time cuts [true, false], answer [false, true] → cuts [false, true]; groups [p0, p1] Merged and [p2] Split
test('a merge across a time cut is marked merged');                         // time [true], AI [false] → one group, Merged
test('a split inside one time group is marked split');                      // 12 photos, no time cuts, AI cut at pair 5 → two groups, both Split
test('each window sends at most eight photos');                             // assertPrompted(fn ($p) => $p->attachments->count() <= 8)
test('a provider failure keeps the decided cuts and records the reason');   // second window throws key-rejected HTTP 401 with an active provider → first window's cuts kept, failure_reason KeyRejected, failure_provider name, grouping_incomplete true, status Ready
test('a failure without a provider marks the grouping incomplete');          // failure_reason null, grouping_incomplete true
test('the job resumes with a cursor when the budget runs out');             // Carbon::setTestNow advanced inside the fake; Queue::assertPushed(GroupBulkImport::class, fn ($j) => $j->nextPair === <first pair of second window>)
test('failed() leaves a ready bulk import with the time groups');
test('a deleted bulk import ends the job quietly');                         // delete row inside the fake → no exception reported, no row recreated
test('a single photo becomes one group without calling the AI');
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs/GroupBulkImportTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** the job and the two model methods.

- [ ] **Step 4: Run the tests** — Expected: PASS. Also update the queue timing invariant in `CLAUDE.md` (`GroupBulkImport` has `$tries = 1`, `$timeout = 200`, budget 150 s, client 45 s).

- [ ] **Step 5: Commit** — `Group bulk imports with the AI boundary check (TOR-10)`

---

### Task 5: Upload page ("Viele erfassen")

**Files:**
- Create: `app/Filament/Resources/BulkImports/BulkImportResource.php`, `.../Pages/CreateBulkImport.php`, `.../Schemas/BulkImportForm.php`, `app/Filament/Forms/Components/BulkPhotos.php`, `resources/views/filament/forms/components/bulk-photos.blade.php`, `resources/js/bulk-photos.js`, `resources/js/photo-shrink.js`, `resources/css/bulk-import.css`
- Modify: `resources/js/item-photos.js` (import the shared shrink code), `vite.config.js`, `app/Providers/Filament/AdminPanelProvider.php` (render hook), `config/livewire.php` (`temporary_file_upload.middleware` → `'throttle:150,1'`, because 100 uploads exceed the default 60 per minute), `package.json` (`"exifr": "7.1.3"`)
- Test: `tests/Feature/Panel/CreateBulkImportTest.php`

**Interfaces:**
- Consumes: `BulkImport::start()`, `StagedPhoto`, `SpotSelect`.
- Produces:
  - Resource slug `bulk-imports`, model labels "Bulkimport"/"Bulkimporte", `shouldRegisterNavigation = false`, pages `create` (`/create`) and `view` (`/{record}`, Task 7). Breadcrumbs: house › "Importe" (link to the import list) › "Viele erfassen".
  - `BulkPhotos` field, state `{uploads: array<string, TemporaryUploadedFile>, meta: array<string, {name: string, taken_at: ?string}>}`; `BulkPhotos::stagedPhotos(mixed $state): list<StagedPhoto>` keeps only real uploaded files, takes `name` (max 255) and parses `taken_at` (invalid → null). Field rule: every file `image`, the four mimetypes, `max:12288` ("Nur JPEG-, PNG-, WebP- oder GIF-Bilder bis 12 MB sind erlaubt."); 1 to 100 files ("Mindestens ein Foto." / "Höchstens 100 Fotos pro Bulkimport.").
  - `photo-shrink.js` exports `ACCEPTED_TYPES`, `MAX_BYTES`, `isAcceptable(file)`, `fitWithinLimit(file)` (moved unchanged from `item-photos.js`).
  - `bulk-photos.js` registers Alpine `bulkPhotos`: picking several files at once; per file reads the capture time with `exifr.parse(file, ['DateTimeOriginal'])` **before** `fitWithinLimit`, falls back to `new Date(file.lastModified)`, sends `taken_at` as `toISOString()` and the original name in `meta`; uploads one at a time with `$wire.$upload`; tiles with spinner, German rejection message and ✕ to remove; counter "{n} / 100 Fotos"; a selection that would exceed 100 is not added ("Höchstens 100 Fotos pro Bulkimport."); uses the existing counter store `toryPhotoUploads` so the submit button is disabled while uploads run.
  - `CreateBulkImport`: title and breadcrumb "Viele erfassen", submit label "Gruppieren", `handleRecordCreation()` calls `BulkImport::start()`, `afterCreate()` sets `last_spot_id`, redirect to the view page, notification "Bulkimport angelegt".

- [ ] **Step 1: Write the failing tests** (`Livewire::test(CreateBulkImport::class)`, `UploadedFile::fake()->image()` set into `data.photos.uploads.{key}`)

```php
test('the upload page creates a bulk import and dispatches grouping');  // status Grouping, spot, user, photos sorted by the sent taken_at, last_spot_id set, redirect to view
test('the spot is preselected with the last spot');
test('photos without taken_at are ordered by name after timed ones');
test('a wrong file type is rejected');                                  // text/plain → error, no BulkImport
test('a file over 12 MB is rejected');
test('more than 100 photos are rejected');
test('a forged non-file upload value is ignored');                      // string in uploads → treated as no photo
test('an invalid taken_at is treated as missing');
test('the resource has no navigation entry');
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/CreateBulkImportTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement.** Install with `docker compose exec vite npm install exifr@7.1.3 --save-exact`. Model the field and Blade view on `ItemPhotos` / `item-photos.blade.php`.

- [ ] **Step 4: Run the tests and the item photo tests** (`tests/Feature/Panel/ItemResourceTest.php`) — Expected: PASS. Run `docker compose exec vite npm run build` — Expected: build succeeds.

- [ ] **Step 5: Commit** — `Add the bulk upload page (TOR-10)`

---

### Task 6: Review edits and confirm on the model

**Files:**
- Modify: `app/Models/BulkImport.php`
- Create: `app/BulkImports/StaleBulkImport.php` (`RuntimeException`)
- Test: `tests/Feature/Models/BulkImportReviewTest.php`

**Interfaces:**
- Produces (each locks the row, re-reads `groups` and `discarded`, applies the change, saves; throws `StaleBulkImport` when the bulk import is not `Ready` or a named group or path is not part of it; any edit clears the `mark` of every card it changes):
  - `movePhoto(string $path, string $groupId, int $position): void` — removes the path from its card and inserts it into the target card at `$position` (clamped); drops a card left without photos.
  - `splitBefore(string $path): void` — the card holding `$path` ends before it; `$path` and the following photos become a new card right after. Throws `StaleBulkImport` when `$path` is first in its card.
  - `mergeWithNext(string $groupId): void` — appends the next card's photos; throws when it is the last card.
  - `setDiscarded(string $path, bool $discarded): void`.
  - `remainingPhotos(PhotoGroup $group): list<string>` (photos minus discarded), `importCount(): int` (cards with at least one remaining photo), `canConfirm(): bool` (`Ready`, `importCount() > 0`, no card with more than `Import::MAX_PHOTOS` remaining).
  - `confirm(): list<Import>` — in one transaction: lock; `ModelNotFoundException` when the row is gone; `ValidationException` (key `confirm`) unless `canConfirm()`; create one `Import` per card with remaining photos, in card order, `pending`, same `user_id` and `spot_id`; delete the row with a query-builder delete (no model event); after commit dispatch `ExtractImport` per import and delete the discarded files (`report()` a failed deletion).

- [ ] **Step 1: Write the failing tests** (factory `ready([...])`)

```php
test('a photo moves into another card at a position');
test('a card left empty by a move disappears');
test('splitting before a photo creates the next card');
test('splitting before the first photo of a card is stale');
test('merging appends the next card');
test('merging the last card is stale');
test('discarding and restoring a photo');
test('a card with only discarded photos stays and counts for no import');
test('an edit clears the AI mark of the cards it changes');
test('an unknown group or path is stale and changes nothing');           // forged path from another bulk import
test('edits are refused while grouping');
test('more than twenty remaining photos block confirm');
test('confirm creates one import per card in order and hands over the files');  // imports' photos, spot, user, status Pending; files still exist; bulk row gone
test('confirm dispatches extraction for every import');
test('confirm deletes the discarded files only');
test('a second confirm answers not found and creates nothing');          // two instances of the same record; second throws ModelNotFoundException; Import count unchanged
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/BulkImportReviewTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** the methods.

- [ ] **Step 4: Run the tests** — Expected: PASS.

- [ ] **Step 5: Commit** — `Add review edits and confirm to bulk imports (TOR-10)`

---

### Task 7: Review page

**Files:**
- Create: `app/Filament/Resources/BulkImports/Pages/ViewBulkImport.php`, `resources/views/filament/resources/bulk-imports/view.blade.php`
- Modify: `BulkImportResource.php` (register `view`), `resources/css/bulk-import.css`
- Test: `tests/Feature/Panel/ViewBulkImportTest.php`

**Interfaces:**
- Consumes: Task 6 methods, `GroupMark` labels, `BulkImport::failureMessage()`.
- Produces: `ViewBulkImport` (resource page with a custom view) with Livewire methods `movePhoto(string $path, string $groupId, int $position)`, `splitBefore(string $path)`, `mergeWithNext(string $groupId)`, `discard(string $path)`, `restore(string $path)`; each calls the model method and on `StaleBulkImport` sends a warning notification "Die Gruppen wurden inzwischen geändert. Die Seite zeigt jetzt den aktuellen Stand." and refreshes the record.

Page content (spec, "Review page"): title "Bulkimport"; breadcrumbs house › "Importe" › "Bulkimport". While `Grouping`: "Wird gruppiert …", `wire:poll.5s`, no cards, only "Löschen". When `Ready`: the note (provider reason as `{message} Provider prüfen` linking to "Eigene KI" when `failure_reason` is set; else, when `grouping_incomplete`, "Die KI-Prüfung ist nicht vollständig gelaufen. Ein Teil der Gruppen beruht nur auf der Aufnahmezeit."); header with the spot's `label`, "Löschen" (`DeleteAction`, redirect to the import list) and `confirm` ("{n} Importe anlegen", disabled unless `canConfirm()`, then `confirm()`, `last_spot_id`, notification "{n} Importe angelegt", redirect to the import list). Cards as in the spec: "Gruppe {n} · {count} Fotos", mark badge, "Mit nächster zusammenlegen ↓" except on the last card, thumbnails (temporary signed links like `ItemPhotos::getPhotos()`), ✂ between photos, ✕ / "rückgängig", red hint "Höchstens 20 Fotos pro Import. Bitte aufteilen." on a card over 20 remaining photos. Drag between cards with Livewire 4 `wire:sort` and `wire:sort:group` (check with `search-docs` for the exact attribute and handler signature); `wire:sort:item` is the path, the card's key is the group id.

- [ ] **Step 1: Write the failing tests**

```php
test('a grouping bulk import shows the waiting state and polls');
test('a ready bulk import shows its cards with counts and marks');
test('the provider reason links to Eigene KI');
test('an incomplete grouping shows the general note');
test('moving, splitting, merging and discarding call through');          // call('movePhoto', …) etc. → record changed
test('a stale edit shows the notice and the current cards');             // edit a group id that was merged meanwhile → assertNotified, record unchanged
test('confirm creates the imports and redirects to the import list');    // callAction('confirm')
test('confirm is disabled over twenty photos in a card');                // assertActionDisabled('confirm')
test('delete removes the bulk import and its files');
test('an unknown record answers 404');
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/ViewBulkImportTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** the page, view and CSS.

- [ ] **Step 4: Run the tests** — Expected: PASS. Build assets (`npm run build`).

- [ ] **Step 5: Commit** — `Add the bulk import review page (TOR-10)`

---

### Task 8: Entry points, open bulk imports and docs

**Files:**
- Create: `app/Filament/Widgets/OpenBulkImportsWidget.php`
- Modify: `app/Filament/Pages/Dashboard.php`, `app/Filament/Resources/Imports/Pages/ListImports.php`, `resources/css/item-grid.css` (only if the stacked column needs it), `CLAUDE.md` (architecture: bulk import, dashboard, navigation note), `CONTEXT.md` (term "Bulk import" (Bulkimport): many photos of several things on their way to becoming imports; _Avoid_: batch, Sammelimport)
- Test: `tests/Feature/Panel/DashboardTest.php`, `tests/Feature/Panel/ListImportsTest.php`

**Interfaces:**
- Produces: `OpenBulkImportsWidget` (`TableWidget`, not lazy, `canView()` when any bulk import exists, heading "Offene Bulkimporte", columns "{n} Fotos" and status badge, latest first, record URL to the view page, `poll('10s')`). Action `captureMany` "Viele erfassen" linking to the bulk create page, on the dashboard after "Erfassen" and on the import list after "Erfassen". The dashboard stacks `OpenBulkImportsWidget` above `OpenImportsWidget` in the same column of the row with `LocationsWidget`; the row counts that column once when either widget is visible. The import list shows the widget as a header widget.

- [ ] **Step 1: Write the failing tests**

```php
test('the dashboard offers Viele erfassen');
test('the dashboard lists open bulk imports with their status');
test('the dashboard hides open bulk imports when there are none');
test('the import list offers Viele erfassen and shows open bulk imports');
```

- [ ] **Step 2: Run them and see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/DashboardTest.php tests/Feature/Panel/ListImportsTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement** the widget, actions, layout and docs.

- [ ] **Step 4: Run the whole suite**

Run: `docker compose exec app php artisan test --compact` then `./vendor/bin/pint --dirty --format agent` and `./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 5: Commit** — `Show bulk imports on the dashboard and import list (TOR-10)`

---

### Task 9: Release checks (no new code unless a check fails)

- [ ] **Step 1: Vendor limits.** Check the current docs of Claude, OpenAI, Mistral and Ollama (`laravel/ai` passes images inline) for the maximum images and request size per call. Expected: 20 images of up to ~2 MB each fit. If one does not, stop and report to the user before changing anything.
- [ ] **Step 2: Local model.** Add an `extraction:eval` case with 20 photos of one thing and run `docker compose exec app php artisan extraction:eval --provider="Ollama lokal" --runs=3`; run one bulk import live on Ollama with ~15 photos of 4 things. Report the results to the user (quality of extraction with 20 photos, quality of the boundary answers); do not tune the prompt without the user.
- [ ] **Step 3: Manual browser check** on a desktop browser with real phone photos, some without EXIF (a screenshot, a photo sent through a messenger): order, grouping, drag and drop between cards, split, merge, discard and undo, confirm, light and dark mode; and once on a phone that nothing breaks.
- [ ] **Step 4: Commit** the eval case, if added — `Add a 20-photo extraction eval case (TOR-10)`
