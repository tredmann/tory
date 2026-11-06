# Add and Delete Item Photos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One combined "Fotos" field on the item edit page adds photos, deletes photos and chooses the overview photo, all applied on "Speichern".

**Architecture:** `Item::changePhotos()` applies a set of changes to the row's current photos under a lock and deletes removed files only after commit. A custom Filament field holds those changes as form state, and `EditItem` passes them to the model in one transaction with the other fields. An Alpine component in the field shrinks images in the browser and uploads them through Livewire's temporary uploads.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Alpine, plain CSS, Pest. No new dependency.

**Spec:** `docs/superpowers/specs/2026-11-06-item-photo-editing-design.md`

## Global Constraints

- No new dependency, and no change to `.env` or the Docker image.
- UI copy is German. The field works by keyboard and on phones, in light and dark mode.
- Styling is plain CSS; uncompiled Tailwind classes do not exist in the panel.
- Photos stay on the private `local` disk under `imports/`, shown through temporary signed links.
- JPEG, PNG, WebP or GIF, at most 12 MB per file, shrunk to fit 2048 × 2048 px, never enlarged.
- At least 1 and at most 6 photos after saving.
- Filament's `afterSave` runs before any commit, so file deletion must use `DB::afterCommit`.
- Run everything in Docker: `docker compose exec app …`, `docker compose exec vite npm …`.
- End commit messages with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Commit on branch `tor-3-item-photos`; do not push.

## Review Focus

- Pressing "Speichern" twice after adding a photo: the photo is stored once, because the form is refilled after a save (test in Task 2).
- A second tab saves an old state after a photo was deleted: the deleted photo stays gone and `photos` has no missing path (test in Task 1).
- A forged request removes or chooses a path that is not on the item: no file is touched and the list is unchanged (test in Task 1).
- A save fails after new files were stored: the row and the old files stay, and the stored new files are removed (test in Task 1).
- A non-file value sent in the uploads state, for example a string: it is ignored rather than stored or crashing (test in Task 2).

---

### Task 1: Apply photo changes on the model

**Files:**
- Modify: `app/Models/Item.php`
- Test: `tests/Feature/ItemPhotoChangesTest.php` (create with `php artisan make:test --pest ItemPhotoChangesTest --no-interaction`)

**Interfaces:**
- Produces: `Item::changePhotos(array $removed, array $uploads, ?string $overview): void`
  - `$removed`: `list<string>` of paths.
  - `$uploads`: `array<string, UploadedFile>`, keyed by a client-made key.
  - `$overview`: a photo path or an upload key, or null.
  - Throws `ValidationException` with the error key `data.photo_edits` when the result would have 0 or more than 6 photos.

- [ ] **Step 1: Write the failing tests**

Use `Storage::fake('local')`, `Queue::fake()` after creating the item, `UploadedFile::fake()->image(...)`, and real files via `Storage::put` for existing photos. With photos `a, b, c`:

```php
test('an upload is appended and the overview photo stays first');   // [] , ['k' => file], null → [a, b, c, imports/<new>]; new file exists
test('choosing an upload puts it first');                           // overview 'k' → [<new>, a, b, c]
test('choosing an existing photo keeps the others in order');       // overview 'c' → [c, a, b]
test('removing the overview photo moves the next one forward');     // removed [a], overview a → [b, c]; a.jpg gone; b, c exist
test('the only photo can be replaced in one save');                 // [a], removed [a], ['k' => file] → [<new>]; a gone
test('zero photos are rejected and nothing changes');               // removed all → ValidationException on data.photo_edits with "Mindestens ein Foto"; row and files unchanged
test('more than six photos are rejected and nothing changes');      // 6 + 1 upload → ValidationException "Höchstens 6 Fotos"; no file stored
test('an item without photos may stay without photos');             // [] with no changes → no exception, photos []
test('a stale removal cannot bring back a deleted photo');          // row changed to [b, c] after $item was loaded; stale model calls overview a, removed [] → [b, c]
test('a forged removal touches no file');                           // removed ['imports/other.jpg'], file exists → file still there, photos unchanged
test('a forged overview falls back to the first photo');            // overview 'imports/other.jpg' → [a, b, c]
test('a failed save stores nothing and deletes nothing');           // Item::updating listener throws → a still exists, no new file in imports/, row unchanged
test('changing photos keeps the embedding and dispatches no job');  // Queue::assertNotPushed(EmbedItem::class); embedding not null
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T app php artisan test --compact tests/Feature/ItemPhotoChangesTest.php`
Expected: FAIL with "Call to undefined method … changePhotos".

- [ ] **Step 3: Implement `changePhotos` in `app/Models/Item.php`**

Inside `DB::transaction`, follow this order:

1. Read the current `photos` with `lockForUpdate()` from a fresh query, not from `$this`.
2. Compute the remaining photos with `array_intersect` semantics, so forged paths do nothing.
3. Check the limits before storing anything. Rule: `count > 6` always fails; `count === 0` fails unless the row had no photos before.
4. Store uploads with `$file->store('imports', 'local')`, keeping the key-to-path map.
5. Build the order: overview first if it resolves to a remaining path or an upload key, then remaining photos, then uploads.
6. Call `$this->update(['photos' => …])`.
7. Register `DB::afterCommit` to delete only the removed paths that were on the row. Wrap that deletion in try/catch with `report()`.

Around steps 4 to 6, use try/catch: on any `Throwable`, delete the files stored so far and rethrow.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T app php artisan test --compact tests/Feature/ItemPhotoChangesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Item.php tests/Feature/ItemPhotoChangesTest.php
git commit -m "Apply photo changes to an item under a lock"
```

### Task 2: The field on the edit page, server side

**Files:**
- Create: `app/Filament/Forms/Components/ItemPhotos.php` and `resources/views/filament/forms/components/item-photos.blade.php` (with `php artisan make:filament-form-field ItemPhotos --no-interaction`)
- Modify: `app/Filament/Resources/Items/Schemas/ItemForm.php`, `app/Filament/Resources/Items/Pages/EditItem.php`
- Test: `tests/Feature/Panel/ItemResourceTest.php`

**Interfaces:**
- Consumes: `Item::changePhotos()` from Task 1.
- Produces: field `ItemPhotos::make('photo_edits')` with state `['overview' => ?string, 'removed' => list<string>, 'uploads' => array<string, TemporaryUploadedFile>]`. Task 3 writes to these three paths under the field's state path.
- Produces: `ItemPhotos::getPhotos(): list<array{path: string, url: string}>` for the view, with `url` from `Storage::disk('local')->temporaryUrl($path, now()->addMinutes(30)->endOfHour())`, as Filament does, so re-renders within an hour keep the same link.

- [ ] **Step 1: Adapt the TOR-2 tests and write the new ones**

Replace the six tests that use `overview_photo`, keeping what each one proves. The forged-path test now asserts the fallback from the spec ("falls back to the first remaining photo") instead of a form error. New tests:

```php
test('the edit page shows the photos field with the first photo as overview');  // assertFormSet(['photo_edits.overview' => a]), assertSee('Fotos'), assertSeeHtml photo URL path
test('the photos field also shows on an item with one photo');                  // assertFormFieldVisible('photo_edits')
test('saving passes added, removed and chosen photos to the item');             // set state, call save → photos as expected
test('title and photos change in the same save');                               // both persisted
test('an invalid title changes no photos');                                     // title '' → form error; photos and files unchanged
test('saving twice stores an added photo once');                                // call save twice → one new path
test('a file of the wrong type is rejected');                                   // fake 'notes.jpg' text/plain → assertHasFormErrors(['photo_edits'])
test('a file over 12 MB is rejected');                                          // UploadedFile::fake()->image('big.jpg')->size(12289) → assertHasFormErrors(['photo_edits'])
test('a non-file value in the uploads state is ignored');                       // uploads ['k' => 'imports/other.jpg'] → photos unchanged, no error
test('saving with no photo left shows the error on the field');                 // assertHasErrors(['data.photo_edits'])
test('a save during a regeneration changes no photos');                         // regenerating_at set after mount → photos and files unchanged
```

If `fillForm` does not carry an uploaded file into the nested path, set it with `->set('data.photo_edits.uploads.k', $file)`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T app php artisan test --compact tests/Feature/Panel/ItemResourceTest.php`
Expected: the new and adapted tests FAIL because `photo_edits` does not exist.

- [ ] **Step 3: Implement the field class and a server-rendered view**

- `ItemPhotos extends Field`. In `setUp()`, `formatStateUsing` returns the initial state from the record, and a closure rule validates each `uploads` entry that is an `UploadedFile` with `Validator::make` and `['image', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:12288']`, failing with `Nur JPEG-, PNG-, WebP- oder GIF-Bilder bis 12 MB sind erlaubt.`
- Label `Fotos`, `columnSpanFull()`, placed first in `ItemForm`. Delete `Radio::make('overview_photo')` and `photoOptions()`.
- The view lists the photos through `$getPhotos()` inside the field wrapper. Task 3 adds the interaction.

- [ ] **Step 4: Implement the save in `EditItem`**

Remove the TOR-2 `mutateFormDataBeforeSave`. New signature:

`protected function handleRecordUpdate(Model $record, array $data): Model`

It pulls `photo_edits` out of `$data` and, inside one `DB::transaction`, first calls `$record->update($data)`, then `$record->changePhotos($removed, $uploads, $overview)`, keeping only `UploadedFile` values in `$uploads`.

Add `afterSave()`, which refreshes the record's photos and calls `$this->fillForm()`, so the state no longer holds the saved uploads and the view shows the new list.

The panel has no `databaseTransactions()` set, so this page's own transaction is what makes the save atomic.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec -T app php artisan test --compact tests/Feature/Panel/ItemResourceTest.php tests/Feature/ItemPhotoChangesTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Forms app/Filament/Resources/Items resources/views/filament tests/Feature/Panel/ItemResourceTest.php
git commit -m "Edit the photos of an item through one field"
```

### Task 3: The field in the browser

**Files:**
- Create: `resources/js/item-photos.js`
- Modify: `resources/views/filament/forms/components/item-photos.blade.php`, `resources/css/photo-picker.css`, `vite.config.js`, `app/Providers/Filament/AdminPanelProvider.php`, `app/Filament/Resources/Items/Pages/EditItem.php`, `CLAUDE.md`
- Test: `tests/Feature/Panel/ItemResourceTest.php`

**Interfaces:**
- Consumes: the state paths and `getPhotos()` from Task 2.
- Produces: Alpine component `itemPhotos({ statePath, photos })` and store counter `Alpine.store('toryPhotoUploads')`, read by the save button.

- [ ] **Step 1: Write the failing rendering test**

```php
test('the photos field renders real buttons and the add tile', function () {
    // item with two photos, rendered on EditItem:
    // assertSeeHtml('x-data="itemPhotos(')
    // assertSee('Foto hinzufügen')
    // the save button carries the upload guard: assertSeeHtml('toryPhotoUploads')
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T app php artisan test --compact tests/Feature/Panel/ItemResourceTest.php --filter='real buttons'`
Expected: FAIL on `x-data`.

- [ ] **Step 3: Build the Alpine component and the view**

In `resources/js/item-photos.js`, import `../css/photo-picker.css` and register with `Alpine.data` on `alpine:init`. Check that it registers before Alpine starts on a real page load.

- Choosing and removing call `$wire.$set(path, value, false)`, deferred, on `${statePath}.overview` and `${statePath}.removed`. Do not entangle the whole state, because uploads write into it.
- Removing the current overview moves the mark to the next photo that remains, counting uploads. Marked photos cannot be chosen. A photo that is still uploading or was rejected cannot be chosen either.
- Adding: reject the whole selection with `Höchstens 6 Fotos.` if it would go over 6. Reject a file over 12 MB or of another type on its own tile.
- Shrinking: if the file is not a GIF and is larger than 2048 px on a side, draw it with `createImageBitmap(file, { imageOrientation: 'from-image' })` on a canvas that fits 2048 × 2048 and export it in the same type (JPEG quality 0.9). Otherwise upload the file unchanged.
- Uploading: `$wire.$upload(`${statePath}.uploads.${key}`, blob, ok, error, progress)` with `key` from `crypto.randomUUID()`. Previews use `URL.createObjectURL`. A spinner shows until `ok`. `error` shows `Hochladen fehlgeschlagen.` on the tile, with a dismiss button.
- Each photo is a `<button type="button" aria-pressed>` with a separate ✕ `<button aria-label="Foto entfernen">`. A marked photo shows a `rückgängig` button. The tile is a `<button>` that opens a hidden `<input type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif">`.
- In the view, wrap the grid in `wire:ignore` and key it on the photo list, so a refill after saving restarts the component and validation re-renders don't reset it.

In `photo-picker.css`, restyle for the new markup under `.tory-photo-picker`, keeping the TOR-2 ring, check mark and faded state. Add the grey state and the spinner, and use Filament's `var(--primary-500)`.

- [ ] **Step 4: Wire it up**

- `vite.config.js`: replace the input `resources/css/photo-picker.css` with `resources/js/item-photos.js`.
- `AdminPanelProvider`: load `resources/js/slideshow.js` and `resources/js/item-photos.js` in the `@vite` hook.
- `EditItem::getSaveFormAction()`: add `extraAttributes(['x-bind:disabled' => '$store.toryPhotoUploads > 0'])`.

- [ ] **Step 5: Run the test, the formatter and the analyser**

Run: `docker compose exec -T app php artisan test --compact tests/Feature/Panel/ItemResourceTest.php`
Run: `docker compose exec -T app ./vendor/bin/pint --dirty --format agent`
Run: `docker compose exec -T app ./vendor/bin/phpstan analyse --no-progress`
Expected: PASS, `passed`, `No errors`.

- [ ] **Step 6: Update `CLAUDE.md`**

In the Items section, replace the "Foto für die Übersicht" sentence with the new behaviour: the combined field, `Item::changePhotos()`, deletion after commit, and resizing in the browser. Also correct "Photos … deleted" wording if it now misleads.

- [ ] **Step 7: Run the full suite and the production build**

Run: `docker compose exec -T app php artisan test --compact`
Run: `docker compose exec -T vite npm run build -- --outDir /tmp/tory-build`
Expected: all tests PASS, and the manifest contains `resources/js/item-photos.js`.

- [ ] **Step 8: Commit**

```bash
git add resources vite.config.js app CLAUDE.md tests/Feature/Panel/ItemResourceTest.php
git commit -m "Add, remove and choose item photos in the browser"
```

- [ ] **Step 9: Ask the user for the manual check, which no test covers**

Use a real desktop browser and a real phone against the dev app, with a photo larger than 2048 px:

1. Add one photo and save: it is last, and the overview photo is unchanged.
2. Add a photo, choose it, and save: it is first.
3. ✕ on the overview photo: the mark moves. "rückgängig" brings the photo back. Save: the file is gone.
4. A phone portrait photo stays upright after shrinking and is at most 2048 px.
5. An animated GIF stays animated.
6. At 6 photos the tile is gone. Selecting too many shows the message and adds nothing.
7. "Speichern" is disabled during an upload. "Abbrechen" leaves the item as it was.
8. Keyboard: Tab reaches every button and Enter or Space works. Light and dark mode both look right.
9. Enter in the title field during an upload: check what happens. If the uploading photo is lost silently, report it.
