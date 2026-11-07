# Photo Endpoint and Thumbnails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Photos come from a session-protected panel route (original or 400 px WebP thumbnail), every small photo in panel and API uses the thumbnail, and new photos are stored under `photos/YYYY/MM/`.

**Architecture:** A new `app/Photos/` namespace holds four small classes: `PhotoStore` (where files go, deleting originals with their thumbnails), `Thumbnails` (GD generation under one cache lock), `PhotoResponder` (HTTP responses with cache headers and the fallback) and `PhotoLinks` (panel URLs). A web `PhotoController` registered in `AdminPanelProvider::authenticatedRoutes()` and the existing API `PhotoController` both answer through `PhotoResponder`. Filament image components get thumbnail URLs as their state; `PhotoSlideshow` gets the original URLs.

**Tech Stack:** Laravel 13, Filament 5, GD (with `exif`), Pest. No new dependency.

**Spec:** `docs/superpowers/specs/2026-11-07-photo-endpoint-design.md`

## Global Constraints

- Everything runs in Docker: `docker compose exec app …`. Never host PHP.
- Disk is always `local` (`storage/app/private`).
- New originals: `photos/YYYY/MM/<hash name>.<ext>` by upload date. Old ones stay at `imports/<name>`.
- Thumbnail of `<dir>/<name>.<ext>`: `thumbs/<dir>/<name>.webp`.
- Thumbnail: 400 px on the longest edge, never upscaled, WebP, EXIF orientation applied, GIF first frame.
- Only one thumbnail is generated at a time (one cache lock).
- Panel URLs: original `/photos/{route path}`, thumbnail `/photos/thumb/{route path}`, where route path is the storage path with a leading `photos/` dropped (`photos/2026/11/a.jpg` → `2026/11/a.jpg`; `imports/a.jpg` → `imports/a.jpg`).
- Served storage paths must match `^(imports/[^/]+|photos/\d{4}/\d{2}/[^/]+)$` with a file name other than `.` and `..`; everything else is 404.
- Successful photo responses: `Cache-Control: private, max-age=31536000, immutable`. Fallback (original served at a thumbnail URL): `Cache-Control: private, no-cache`.
- Panel photo route needs Filament's auth **and** `$panel->getEmailVerifiedMiddleware()`.
- API: `photos` unchanged; new `thumbnails` list in the same order; thumbnail routes end in `/thumb`.
- Tests: `Storage::fake('local')`, `Queue::fake()` where jobs would run, never assert literal ids. Before each commit: `./vendor/bin/pint --dirty` and `./vendor/bin/phpstan analyse`.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. A photo record that names a file missing on disk must show the next existing photo or the placeholder, never a broken image, and the slideshow positions must still match. → test in Task 5.
2. A photo stored in a month folder (`photos/2026/11/…`) must be found by the API file-name lookup, which matches on basename only. → test in Task 6.
3. A thumbnail request while another generation holds the lock past the wait must get the original with `no-cache`, not an error. → test in Task 3.
4. A URL like `/photos/thumb/../.env`, `/photos/imports/..`, `/photos/evals/x.json` or `/photos/livewire-tmp/x.jpg` must 404. → test in Task 4.
5. Discarding a photo in a bulk import and editing an item's photos must delete the thumbnail along with the original; handing over must keep both. → tests in Task 2.

---

### Task 1: `PhotoStore` paths and the dated upload directory

**Files:**
- Create: `app/Photos/PhotoStore.php`
- Modify: `app/Filament/Resources/Imports/Schemas/ImportForm.php`, `app/Models/BulkImport.php:121`, `app/Models/Item.php:118`, `app/Http/Controllers/Api/V1/ImportController.php:34`, `app/Http/Controllers/Api/V1/ItemController.php:73`
- Test: `tests/Unit/PhotoStoreTest.php` (create), `tests/Feature/Panel/CreateImportTest.php:97`, `tests/Feature/Api/ItemsTest.php:218`, `tests/Feature/Api/ImportsTest.php:40`, plus the bulk import and item photo tests that store uploads

**Interfaces:**
- Produces (all `public static`, `final class App\Photos\PhotoStore`):
  - `directory(): string` — `'photos/'.now()->format('Y/m')`
  - `store(UploadedFile $file): string|false` — `$file->store(self::directory(), 'local')`
  - `thumbnailPath(string $path): string` — `thumbs/<dirname>/<filename without ext>.webp`
  - `routePath(string $path): string` — drop a leading `photos/`
  - `storagePath(string $routePath): ?string` — inverse of `routePath`; `null` unless the result matches the allowed pattern from Global Constraints

- [ ] **Step 1: Write the failing unit tests** in `tests/Unit/PhotoStoreTest.php`:

```php
it('stores into the month folder', function () {
    $this->travelTo('2026-11-07 12:00');
    expect(PhotoStore::directory())->toBe('photos/2026/11');
});

it('names the thumbnail after the original', function () {
    expect(PhotoStore::thumbnailPath('photos/2026/11/abc.jpg'))->toBe('thumbs/photos/2026/11/abc.webp')
        ->and(PhotoStore::thumbnailPath('imports/abc.png'))->toBe('thumbs/imports/abc.webp');
});

it('maps storage paths to route paths and back', function (string $storage, string $route) {
    expect(PhotoStore::routePath($storage))->toBe($route)
        ->and(PhotoStore::storagePath($route))->toBe($storage);
})->with([
    ['photos/2026/11/abc.jpg', '2026/11/abc.jpg'],
    ['imports/abc.jpg', 'imports/abc.jpg'],
]);

it('refuses route paths outside the photo folders', function (string $route) {
    expect(PhotoStore::storagePath($route))->toBeNull();
})->with(['../.env', 'imports/..', 'imports/.', 'imports/a/b.jpg', 'evals/x.json', 'livewire-tmp/x.jpg', 'thumb/imports/a.jpg', '2026/1/a.jpg', '']);
```

(`travelTo` needs the Laravel test case; if `tests/Unit` does not boot it, put the file in `tests/Feature/Photos/PhotoStoreTest.php` instead.)

- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact tests/Unit/PhotoStoreTest.php` — Expected: FAIL, class not found.
- [ ] **Step 3: Implement `PhotoStore`** with the signatures above.
- [ ] **Step 4: Run** the same command — Expected: PASS.
- [ ] **Step 5: Change the directory assertions to fail first.** In the three tests listed under Files, replace `toStartWith('imports/')` with `toMatch('#^photos/\d{4}/\d{2}/[^/]+$#')`; find any other test that asserts the directory of a freshly stored upload with `grep -rn "toStartWith('imports/')" tests` and change it the same way. Run them — Expected: FAIL.
- [ ] **Step 6: Switch every writer** to `PhotoStore`: `ImportForm`'s `FileUpload` gets `->directory(fn (): string => PhotoStore::directory())`; the four `->store('imports', 'local')` calls become `PhotoStore::store($file)` (keep each caller's existing `false` and size checks).
- [ ] **Step 7: Run** `docker compose exec app php artisan test --compact` — Expected: all PASS.
- [ ] **Step 8: Commit** — `git commit -m "Store new photos in month folders (TOR-13)"`

---

### Task 2: Delete thumbnails with their originals

**Files:**
- Modify: `app/Photos/PhotoStore.php`, `app/Models/Import.php:49`, `app/Models/Item.php:62,143,152`, `app/Models/BulkImport.php:70,156,449`, `app/Http/Controllers/Api/V1/ItemController.php:90`
- Test: `tests/Feature/Models/ImportTest.php`, `tests/Feature/Models/ItemTest.php`, `tests/Feature/Models/BulkImportTest.php`, `tests/Feature/ItemPhotoChangesTest.php`

**Interfaces:**
- Consumes: `PhotoStore::thumbnailPath()` (Task 1)
- Produces: `PhotoStore::delete(array $paths): bool` — deletes each path and its thumbnail in one `Storage::disk('local')->delete()` call; returns that call's result. A missing thumbnail is not a failure.

- [ ] **Step 1: Write the failing tests**, one per deleter, each storing an original and its thumbnail (`Storage::disk('local')->put(PhotoStore::thumbnailPath($path), 'thumb')`) and asserting both are missing afterwards:
  - `ImportTest`: `it('deletes the thumbnails with the photos')` — `$import->delete()`.
  - `ItemTest`: same with `$item->delete()`.
  - `BulkImportTest`: `$bulkImport->delete()` removes all thumbnails; confirming removes the thumbnails of discarded photos and **keeps** the thumbnails of handed-over photos (`assertExists`).
  - `ItemPhotoChangesTest`: removing a photo through `changePhotos()` removes its thumbnail.
  - `ImportTest`: `convertToItem()` keeps original and thumbnail.
- [ ] **Step 2: Run** those four files — Expected: the deleting tests FAIL, the hand-over tests PASS.
- [ ] **Step 3: Implement `PhotoStore::delete()`** and replace every `Storage::disk('local')->delete(<photo paths>)` listed under Files with it. After this, `grep -rn "Storage::disk('local')->delete" app` lists no photo paths.
- [ ] **Step 4: Run** `docker compose exec app php artisan test --compact` — Expected: all PASS.
- [ ] **Step 5: Commit** — `git commit -m "Delete thumbnails with their photos (TOR-13)"`

---

### Task 3: `Thumbnails` generation

**Files:**
- Create: `app/Photos/Thumbnails.php`
- Test: `tests/Feature/Photos/ThumbnailsTest.php`

**Interfaces:**
- Consumes: `PhotoStore::thumbnailPath()`
- Produces: `final class App\Photos\Thumbnails` with
  - `public const LOCK = 'photo-thumbnails';`
  - `public function __construct(private int $waitSeconds = 30)` (tests pass `0`)
  - `public function for(string $path): ?string` — the thumbnail's storage path; creates it when missing; `null` when the original is unreadable, decoding fails or the lock wait runs out. Failures other than the lock timeout are `report()`ed.

Approach: return early if the thumbnail exists. Otherwise `Cache::lock(self::LOCK, 60)->block($this->waitSeconds, …)`; inside, check again whether the thumbnail exists (another request may have made it), then decode with `imagecreatefromstring()`, apply EXIF orientation for JPEGs (`exif_read_data()` on `Storage::disk('local')->path($path)`, orientations 2–8 via `imageflip`/`imagerotate`), scale to 400 px on the longest edge only if larger (`imagescale`), keep alpha (`imagealphablending(false)`, `imagesavealpha(true)`), encode with `imagewebp` (quality 80) into a string, `put()` it to `<thumbnail>.<Str::random(8)>.tmp` and `move()` it into place. Catch `LockTimeoutException` → `null` without reporting.

- [ ] **Step 1: Write the failing tests** (create images with GD in a helper; `Storage::fake('local')`; `new Thumbnails(waitSeconds: 0)`):

```php
it('makes a 400 px webp thumbnail and stores it', function () {
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(2048, 1536));

    $path = (new Thumbnails(0))->for('photos/2026/11/a.jpg');

    expect($path)->toBe('thumbs/photos/2026/11/a.webp');
    [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height, $type])->toBe([400, 300, IMAGETYPE_WEBP]);
});

it('does not upscale a small photo', /* 200x100 png → 200x100 */);
it('keeps the stored thumbnail on the second call', /* put a marker file at the thumbnail path first; for() returns it and the content is still the marker */);
it('applies the exif orientation', /* jpeg 400x200 with orientation 6 → thumbnail is 200x400 */);
it('uses the first frame of a gif', /* imagegif output → webp thumbnail */);
it('handles a 24 megapixel photo', /* jpeg(6000, 4000) → 400x267 */);
it('returns null and reports a corrupt photo', function () {
    Exceptions::fake();
    Storage::disk('local')->put('imports/broken.jpg', 'not an image');
    expect((new Thumbnails(0))->for('imports/broken.jpg'))->toBeNull();
    Exceptions::assertReportedCount(1);
    Storage::disk('local')->assertMissing('thumbs/imports/broken.webp');
});
it('returns null without reporting while the lock is held', function () {
    Exceptions::fake();
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));
    $lock = Cache::lock(Thumbnails::LOCK, 60);
    $lock->get();
    expect((new Thumbnails(0))->for('imports/a.jpg'))->toBeNull();
    Exceptions::assertNothingReported();
    $lock->release();
});
it('leaves no temporary file behind', /* after for(), allFiles('thumbs') is exactly the thumbnail */);
```

  EXIF fixture: write the JPEG with GD, then insert this APP1 segment right after the first two bytes (SOI) — orientation 6:

```php
$app1 = "\xFF\xE1\x00\x22"."Exif\x00\x00"."MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";
$withExif = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
```

- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact tests/Feature/Photos/ThumbnailsTest.php` — Expected: FAIL, class not found.
- [ ] **Step 3: Implement `Thumbnails`** as described.
- [ ] **Step 4: Run** the same command — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Generate photo thumbnails with GD (TOR-13)"`

---

### Task 4: Panel photo routes

**Files:**
- Create: `app/Photos/PhotoResponder.php`, `app/Photos/PhotoLinks.php`, `app/Http/Controllers/PhotoController.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php:44-46`
- Test: `tests/Feature/Panel/PhotoRoutesTest.php` (create)

**Interfaces:**
- Consumes: `PhotoStore::storagePath()`, `PhotoStore::routePath()`, `Thumbnails::for()`
- Produces:
  - `final class App\Photos\PhotoResponder` (constructor `private Thumbnails $thumbnails`):
    - `original(string $path): StreamedResponse` — `abort(404)` unless the file exists; immutable cache headers.
    - `thumbnail(string $path): StreamedResponse` — 404 unless the original exists; the thumbnail with immutable headers, or the original with `private, no-cache` when `Thumbnails::for()` returns `null`.
  - `App\Http\Controllers\PhotoController` with `original(string $path, PhotoResponder $responder)` and `thumbnail(string $path, PhotoResponder $responder)`; each maps with `PhotoStore::storagePath()` and 404s on `null`.
  - Route names `filament.admin.photos.original` (`/photos/{path}`, `where('path', '(?!thumb/).+')`) and `filament.admin.photos.thumbnail` (`/photos/thumb/{path}`, `where('path', '.+')`), both with `->middleware($panel->getEmailVerifiedMiddleware())` inside `authenticatedRoutes(function (Panel $panel): void { … })`.
  - `final class App\Photos\PhotoLinks` (static): `original(string $path): string`, `thumbnail(string $path): string` (route URLs via `PhotoStore::routePath()`), `existing(array $paths): array` (list of the paths whose file exists, order kept).

- [ ] **Step 1: Write the failing tests** (`Storage::fake('local')`, verified user unless stated):
  - `it('serves an original with immutable caching')` — `get('/photos/2026/11/a.jpg')` OK, `Content-Type` `image/jpeg`, `$response->headers->getCacheControlDirective('immutable')` true, `max-age` `'31536000'`, `private` true.
  - `it('serves an old photo')` — `/photos/imports/a.jpg`.
  - `it('serves a webp thumbnail')` — `/photos/thumb/2026/11/a.jpg` with a real JPEG: `Content-Type` `image/webp`, immutable; `thumbs/photos/2026/11/a.webp` exists.
  - `it('falls back to the original without caching')` — corrupt file at `imports/broken.jpg`: thumbnail URL OK, body is the original bytes, `no-cache` directive true, `immutable` absent.
  - `it('answers 404 outside the photo folders')` — dataset of the Review Focus 4 URLs plus a missing `/photos/imports/missing.jpg` and `/photos/thumb/imports/missing.jpg`; place a real file at `evals/x.json` and `livewire-tmp/x.jpg` first.
  - `it('sends guests to the login')` — `assertRedirect('/login')`.
  - `it('sends unverified users to the verification prompt')` — `assertRedirect('/email-verification/prompt')` (same as `CreateImportTest`).
  - `it('builds links to both routes')` — `PhotoLinks::original('photos/2026/11/a.jpg')` ends with `/photos/2026/11/a.jpg`, `PhotoLinks::thumbnail('imports/a.jpg')` ends with `/photos/thumb/imports/a.jpg`; `PhotoLinks::existing(['imports/missing.jpg', 'imports/b.jpg'])` is `['imports/b.jpg']`.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact tests/Feature/Panel/PhotoRoutesTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** the three classes and the routes. Headers go through `Storage::disk('local')->response($path, null, ['Cache-Control' => …])`.
- [ ] **Step 4: Run** the same command, then `php artisan route:list --path=photos` — Expected: PASS; both routes listed with the email-verified middleware.
- [ ] **Step 5: Commit** — `git commit -m "Serve photos and thumbnails through panel routes (TOR-13)"`

---

### Task 5: Thumbnails in the panel

**Files:**
- Modify: `app/Filament/Schemas/PhotoSlideshow.php`, `app/Filament/Schemas/ItemGrid.php`, `app/Filament/Resources/Imports/Tables/ImportsTable.php`, `app/Filament/Resources/Imports/Schemas/ImportInfolist.php`, `app/Filament/Resources/Items/Schemas/ItemInfolist.php`, `app/Filament/Forms/Components/ItemPhotos.php:73-83`, `app/Filament/Resources/BulkImports/Pages/ViewBulkImport.php:130-150`
- Test: `tests/Feature/Panel/PhotoSlideshowTest.php`, `tests/Feature/Panel/ItemResourceTest.php:203-212`, `tests/Feature/Panel/ViewBulkImportTest.php`

**Interfaces:**
- Consumes: `PhotoLinks::original()`, `PhotoLinks::thumbnail()`, `PhotoLinks::existing()`
- Produces: `PhotoSlideshow::imgAttributes(array $paths, string $clickedUrl): array` — `$clickedUrl` is the thumbnail URL the `<img>` shows; the slideshow URLs are `PhotoLinks::original()` of the existing paths. The `ImageColumn|ImageEntry $component` parameter goes away.

Each `ImageColumn`/`ImageEntry` drops `->disk()`/`->visibility()` and gets `->state(fn (Item|Import $record): array => array_map(PhotoLinks::thumbnail(...), PhotoLinks::existing($record->photos ?? [])))`; its `extraImgAttributes` closure passes `$record->photos` and `$state`. Filament returns a URL state unchanged (`ImageColumn::getImageUrl()`), so no disk is involved. `ItemPhotos::getPhotos()` and `ViewBulkImport::cards()` use `PhotoLinks::thumbnail($path)` instead of `temporaryUrl()`; the `$expiry` variables go.

- [ ] **Step 1: Change the tests to fail first** in `PhotoSlideshowTest.php`:
  - Existing slideshow tests additionally assert `expect($images[0]['urls'][0])->toEndWith('/photos/imports/a.jpg')` (original, not thumb).
  - New `test('thumbnails show the small variant', …)->with('all surfaces')` — the HTML contains `PhotoLinks::thumbnail('imports/a.jpg')`.
  - `a list thumbnail whose file is missing opens no slideshow` changes on purpose: missing files are left out before the first photo is picked, so the tile shows `b.jpg` and its slideshow holds one URL. Rename it to `a list tile skips a missing first photo`.
  - `ItemResourceTest` `the edit page shows the photos field…`: expect `PhotoLinks::thumbnail('imports/a.jpg')` instead of the temporary URL path.
  - `ViewBulkImportTest`: the review page shows `PhotoLinks::thumbnail(...)` for a group photo.
- [ ] **Step 2: Run** those three files — Expected: FAIL.
- [ ] **Step 3: Implement** the changes listed above.
- [ ] **Step 4: Run** `docker compose exec app php artisan test --compact` — Expected: all PASS.
- [ ] **Step 5: Check in the browser** (playwright-cli against http://localhost:8000, logged in): the items grid, an item view page and the slideshow show photos; the network panel shows `/photos/thumb/…` for tiles and `/photos/…` for the opened slideshow photo.
- [ ] **Step 6: Commit** — `git commit -m "Load thumbnails in the panel (TOR-13)"`

---

### Task 6: Thumbnails in the API

**Files:**
- Modify: `routes/api.php:31-32`, `app/Http/Controllers/Api/V1/PhotoController.php`, `app/Http/Resources/Api/V1/ImportResource.php`, `app/Http/Resources/Api/V1/ItemResource.php`, `docs/api-handover.md:95-140`
- Test: `tests/Feature/Api/PhotosTest.php`, `tests/Feature/Api/ItemsTest.php`, `tests/Feature/Api/ImportsTest.php`

**Interfaces:**
- Consumes: `PhotoResponder::original()`, `PhotoResponder::thumbnail()`, `PhotoUrls::for()`
- Produces: routes `api.v1.imports.photos.thumbnail` (`imports/{import}/photos/{photo}/thumb`) and `api.v1.items.photos.thumbnail` (`items/{item}/photos/{photo}/thumb`); resource field `thumbnails` = `PhotoUrls::for('api.v1.<resource>.photos.thumbnail', $this->resource)`.

`PhotoController` keeps its basename lookup over `$record->photos` and hands the found path to `PhotoResponder`; two new methods `importThumbnail()` and `itemThumbnail()`.

- [ ] **Step 1: Write the failing tests** in `PhotosTest.php`:
  - `it('streams an item thumbnail as webp')` and the same for imports.
  - `it('finds a photo in a month folder')` — item with `photos/2026/11/abc.jpg`, `GET …/photos/abc.jpg` OK.
  - `it('answers 404 for a thumbnail of another record')`.
  - `it('requires a token for thumbnails')`.
  - `ItemsTest`/`ImportsTest`: `GET` a record with two photos — `data.thumbnails` has two URLs ending in `/photos/<name>/thumb`, in photo order.
- [ ] **Step 2: Run** the three files — Expected: FAIL.
- [ ] **Step 3: Implement** routes, controller methods and resource fields. Update `docs/api-handover.md`: add `thumbnails` to both JSON examples and the bullet "`thumbnails` matches `photos` one to one, 400 px WebP; use it for tiles and lists", and list the two `/thumb` routes where the photo routes are described.
- [ ] **Step 4: Run** `docker compose exec app php artisan test --compact tests/Feature/Api` — Expected: PASS, including `DocsTest`.
- [ ] **Step 5: Commit** — `git commit -m "Offer photo thumbnails in the API (TOR-13)"`

---

### Task 7: Project docs and final checks

**Files:**
- Modify: `CLAUDE.md`, `CONTEXT.md` (only if it lists photo terms)

- [ ] **Step 1: Update `CLAUDE.md`**:
  - Architecture map, "Items and photos": add `app/Photos/` (`PhotoStore`, `Thumbnails`, `PhotoResponder`, `PhotoLinks`), the panel routes `/photos/…` and `/photos/thumb/…`, spec photo-endpoint.
  - Invariants, "Photo files": photo files are stored and deleted only through `PhotoStore`; a thumbnail at `thumbs/…` belongs to its original's path.
  - Gotchas: replace "Thumbnails use temporary signed URLs, so a tab open past their expiry needs a reload." with "Panel photos come from session-protected `/photos/…` routes; the first visit of a list with photos lacking thumbnails is slow because only one thumbnail is generated at a time." Keep the API sentence.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --compact`, `./vendor/bin/pint --dirty`, `./vendor/bin/phpstan analyse` — Expected: all green.
- [ ] **Step 3: Commit** — `git commit -m "Document photo endpoint (TOR-13)"`
