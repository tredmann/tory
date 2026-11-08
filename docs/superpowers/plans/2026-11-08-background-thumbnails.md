# Background Thumbnails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thumbnails are made by a queue job as soon as photos are stored or a missing one is requested, and the thumbnail routes answer a missing thumbnail with a placeholder PNG instead of the original.

**Architecture:** `App\Photos\Thumbnails` splits into a lookup (`for()`, which only checks and queues) and the generation (`generate()`, called by the new job `App\Jobs\GenerateThumbnail` on queue `thumbnails`). `PhotoResponder` serves a static placeholder when `for()` returns `null`. `PhotoStore::store()` and `CreateImport::afterCreate()` queue the job for new photos. Two supervisord workers process the `thumbnails` queue.

**Tech Stack:** Laravel 13 queues (database driver), `ShouldBeUnique`, GD, Pest, supervisord.

**Spec:** `docs/superpowers/specs/2026-11-08-background-thumbnails-design.md`

## Global Constraints

- Branch `feature/TOR-19-background-thumbnails`; commit messages end with `(TOR-19)` and the `Co-Authored-By` line.
- Everything runs in Docker: `docker compose exec app php artisan test --compact …`, `docker compose exec app ./vendor/bin/pint --dirty`, `docker compose exec app ./vendor/bin/phpstan analyse`.
- Job: `$tries = 1`, `$timeout = 60`, queue `thumbnails`, unique per path. Worker `--timeout=90`, `stopwaitsecs=120`, `numprocs=2`. Invariant: job timeout < worker timeout ≤ 210 < 300 (`retry_after`).
- Placeholder: `Cache-Control: private, no-cache`, never `immutable`; a stored thumbnail keeps `private, max-age=31536000, immutable`.
- A thumbnail request never decodes an original and never returns the original.
- Thumbnail generation itself (400 px, WebP 80, EXIF orientation, `MAX_PIXELS` = 60 000 000, temp file then move) stays unchanged.
- Failed paths are remembered forever in the cache; no migration.
- No new dependency. Constructor injection or typed `handle()` parameters, no `app()`/`resolve()`.
- Tests: `Storage::fake('local')`; `Queue::fake()` unless the job itself runs. `phpunit.xml` uses `QUEUE_CONNECTION=sync` and `CACHE_STORE=array`.

## Review Focus

- The same missing thumbnail requested many times (a list reloaded, several tiles of one photo) → queued once, not once per request. Test in Task 1.
- The original deleted (or its transaction rolled back) before the job runs → the job does nothing and reports nothing. Test in Task 1.
- A photo whose generation failed → later requests neither queue nor report again. Test in Task 1.
- The placeholder response cached by the browser for good → the real thumbnail would never appear after reload. Test asserts `no-cache` and no `immutable` in Task 1.
- Hand-over ("Übernehmen", `Import::convertToItem()`) → queues no thumbnail job for paths that already have one. Test in Task 2.

---

### Task 1: Background generation and placeholder

**Files:**
- Create: `app/Jobs/GenerateThumbnail.php`
- Create: `resources/images/thumbnail-placeholder.png`
- Modify: `app/Photos/Thumbnails.php`
- Modify: `app/Photos/PhotoResponder.php`
- Modify: `app/Http/Controllers/PhotoController.php`, `app/Http/Controllers/Api/V1/PhotoController.php` (return type only)
- Test: `tests/Feature/Photos/ThumbnailsTest.php`, `tests/Feature/Jobs/GenerateThumbnailTest.php` (new), `tests/Feature/Panel/PhotoRoutesTest.php`, `tests/Feature/Api/PhotosTest.php`

**Interfaces:**
- Produces:
  - `App\Jobs\GenerateThumbnail implements ShouldQueue, ShouldBeUnique` with `use Queueable;`, `public function __construct(public string $path)` (calls `$this->onQueue('thumbnails')`), `public int $tries = 1;`, `public int $timeout = 60;`, `uniqueId(): string` returning `$this->path`, `handle(Thumbnails $thumbnails): void` calling `$thumbnails->generate($this->path)`, `failed(?Throwable $exception): void` calling `Thumbnails::markFailed($this->path)` (static, since `failed()` gets no injection).
  - `Thumbnails::for(string $path): ?string`: the thumbnail path when the file exists; otherwise `GenerateThumbnail::dispatch($path)` unless the path is marked failed, then `null`.
  - `Thumbnails::generate(string $path): void`: returns early when the original is missing or the thumbnail exists; otherwise the existing generation; on a caught failure `report($e)` and `self::markFailed($path)`.
  - `Thumbnails::markFailed(string $path): void` (static): `Cache::forever('thumbnail-failed:'.$path, true)`.
  - `Thumbnails::LOCK` is removed.
  - `PhotoResponder::thumbnail(string $path): Response` (`Symfony\Component\HttpFoundation\Response`): 404 when the original is missing, the stored thumbnail (`immutable`) or `response()->file(resource_path('images/thumbnail-placeholder.png'), ['Cache-Control' => 'private, no-cache'])`.

- [ ] **Step 1: Rewrite `ThumbnailsTest` for the split**

Keep the generation tests (400×300 WebP, no upscale, keeps stored thumbnail, EXIF orientation, GIF first frame, 24 MP, no temp file left, pixel limit) but call `(new Thumbnails)->generate($path)` and then read `PhotoStore::thumbnailPath($path)`. Delete the lock test. Change and add:

```php
it('reports a corrupt photo once and marks it as failed', function () {
    Exceptions::fake();
    Queue::fake();
    Storage::disk('local')->put('imports/broken.jpg', 'not an image');

    (new Thumbnails)->generate('imports/broken.jpg');
    expect((new Thumbnails)->for('imports/broken.jpg'))->toBeNull();

    Exceptions::assertReportedCount(1);
    Storage::disk('local')->assertMissing('thumbs/imports/broken.jpg.webp');
    Queue::assertNotPushed(GenerateThumbnail::class);
});

it('returns an existing thumbnail without queuing', function () {
    Queue::fake();
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));
    Storage::disk('local')->put('thumbs/imports/a.jpg.webp', 'marker');

    expect((new Thumbnails)->for('imports/a.jpg'))->toBe('thumbs/imports/a.jpg.webp');
    Queue::assertNothingPushed();
});

it('queues a missing thumbnail once without generating it', function () {
    Queue::fake();
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));

    expect((new Thumbnails)->for('imports/a.jpg'))->toBeNull()
        ->and((new Thumbnails)->for('imports/a.jpg'))->toBeNull();

    Queue::assertPushedOn('thumbnails', GenerateThumbnail::class, fn (GenerateThumbnail $job): bool => $job->path === 'imports/a.jpg');
    Queue::assertPushedTimes(GenerateThumbnail::class, 1);
    Storage::disk('local')->assertMissing('thumbs/imports/a.jpg.webp');
});

it('does nothing when the original is gone', function () {
    Exceptions::fake();

    (new Thumbnails)->generate('imports/gone.jpg');

    Exceptions::assertNothingReported();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
```

Pixel-limit test: `generate()` then assert reported once and thumbnail missing.

- [ ] **Step 2: Write `tests/Feature/Jobs/GenerateThumbnailTest.php`**

```php
it('makes the thumbnail', function () {
    Storage::fake('local');
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(800, 600));

    GenerateThumbnail::dispatch('photos/2026/11/a.jpg');

    Storage::disk('local')->assertExists('thumbs/photos/2026/11/a.jpg.webp');
});

it('marks the path as failed when the job fails', function () {
    Queue::fake();
    Storage::fake('local');
    Storage::disk('local')->put('imports/a.jpg', jpeg(800, 600));

    (new GenerateThumbnail('imports/a.jpg'))->failed(new RuntimeException('killed'));

    expect((new Thumbnails)->for('imports/a.jpg'))->toBeNull();
    Queue::assertNothingPushed();
});

it('runs once, for 60 seconds at most, on the thumbnails queue', function () {
    $job = new GenerateThumbnail('imports/a.jpg');

    expect([$job->tries, $job->timeout, $job->queue, $job->uniqueId()])->toBe([1, 60, 'thumbnails', 'imports/a.jpg']);
});
```

- [ ] **Step 3: Adapt `PhotoRoutesTest` and `Api/PhotosTest`**

`PhotoRoutesTest`: add `Queue::fake()` to `beforeEach`. "serves a webp thumbnail" puts a stored thumbnail first (`Storage::disk('local')->put('thumbs/photos/2026/11/a.jpg.webp', <a real webp>)`, or call `(new Thumbnails)->generate(...)` first) and keeps its assertions. Replace "falls back to the original without caching" with:

```php
it('answers a missing thumbnail with the placeholder and queues it', function () {
    Storage::disk('local')->put('photos/2026/11/a.jpg', jpeg(800, 600));

    $response = $this->get('/photos/thumb/2026/11/a.jpg');

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->getFile()->getPathname())->toBe(resource_path('images/thumbnail-placeholder.png'))
        ->and($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('immutable'))->toBeFalse();
    Queue::assertPushedOn('thumbnails', GenerateThumbnail::class);
    Storage::disk('local')->assertMissing('thumbs/photos/2026/11/a.jpg.webp');
});

it('answers the placeholder for a corrupt photo, not the original', function () {
    Storage::disk('local')->put('imports/broken.jpg', 'not an image');

    $response = $this->get('/photos/thumb/imports/broken.jpg');

    expect($response->getFile()->getPathname())->toBe(resource_path('images/thumbnail-placeholder.png'));
});
```

The 404 dataset stays as is. `Api/PhotosTest`: the two "streams … thumbnail as webp" tests become "answers the placeholder and queues" for a missing thumbnail plus one test that serves a stored thumbnail as WebP; the 404 and token tests stay.

- [ ] **Step 4: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Photos/ThumbnailsTest.php tests/Feature/Jobs/GenerateThumbnailTest.php tests/Feature/Panel/PhotoRoutesTest.php tests/Feature/Api/PhotosTest.php`
Expected: FAIL (`GenerateThumbnail` not found, `generate()` undefined).

- [ ] **Step 5: Create the placeholder PNG**

400×300, fully transparent background, centered image symbol (rounded frame about 120×90 px, a mountain triangle and a sun circle inside) in `#9ca3af` (Tailwind gray-400), drawn once with GD (`imagecreatetruecolor`, `imagesavealpha`, a transparent fill, `imagesetthickness`, `imagepng`) from a throwaway script in the scratchpad, run in the `app` container; commit only the PNG. Check it visually with the Read tool.

- [ ] **Step 6: Implement the job, `Thumbnails` and `PhotoResponder` as in the Interfaces block**

`for()` checks the failed mark with `Cache::has('thumbnail-failed:'.$path)`. Both controllers' `thumbnail` actions change their return type to `Symfony\Component\HttpFoundation\Response`. Update the class docblocks (`for()` no longer creates).

- [ ] **Step 7: Run the tests to verify they pass**

Same command as Step 4. Expected: PASS.

- [ ] **Step 8: Pint, PHPStan, commit**

```bash
docker compose exec app ./vendor/bin/pint --dirty
docker compose exec app ./vendor/bin/phpstan analyse
git add app/Jobs/GenerateThumbnail.php app/Photos resources/images app/Http/Controllers tests
git commit -m "Make thumbnails in a queue job and answer missing ones with a placeholder (TOR-19)"
```

### Task 2: Queue thumbnails for new photos

**Files:**
- Modify: `app/Photos/PhotoStore.php` (`store()`)
- Modify: `app/Filament/Resources/Imports/Pages/CreateImport.php` (`afterCreate()`)
- Test: `tests/Feature/Photos/PhotoStoreTest.php`, `tests/Feature/Panel/CreateImportTest.php`, `tests/Feature/Api/ImportsTest.php`, `tests/Feature/Api/ItemsTest.php`, `tests/Feature/Models/BulkImportTest.php`, `tests/Feature/ItemPhotoChangesTest.php`, `tests/Feature/Models/ImportTest.php`

**Interfaces:**
- Consumes: `GenerateThumbnail` (Task 1).
- Produces: `PhotoStore::store(UploadedFile $file): string|false` queues `GenerateThumbnail::dispatch($path)` when the store succeeded; signature unchanged. `CreateImport::afterCreate()` queues one job per path in `$import->photos`.

- [ ] **Step 1: Write the failing tests**

One test per writer, each with `Queue::fake()` and `Storage::fake('local')`, asserting `Queue::assertPushedOn('thumbnails', GenerateThumbnail::class, fn ($job) => $job->path === <stored path>)` for every stored photo and `Queue::assertPushedTimes(GenerateThumbnail::class, <number of photos>)`:

- `PhotoStoreTest`: `it('queues the thumbnail of a stored photo')` — `PhotoStore::store(UploadedFile::fake()->image('a.jpg'))`.
- `CreateImportTest`: `it('queues a thumbnail per photo')` — panel "Erfassen" with two photos; paths from the created `Import`.
- `Api/ImportsTest`: `it('queues a thumbnail per uploaded photo')` — `POST /api/v1/imports` with two photos.
- `Api/ItemsTest`: `it('queues a thumbnail per uploaded photo')` — `POST /api/v1/items` with two photos.
- `BulkImportTest`: `it('queues a thumbnail per photo')` — `BulkImport::start()` with three staged photos.
- `ItemPhotoChangesTest`: `it('queues thumbnails only for added photos')` — item with one existing photo, `changePhotos([], [one upload], null)` → exactly one job, for the new path.
- `ImportTest`: `it('queues no thumbnail on hand-over')` — `convertToItem(...)` → `Queue::assertNotPushed(GenerateThumbnail::class)`.

Follow each file's existing setup for uploads and actors.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact --filter='queues'`
Expected: the new tests FAIL (nothing pushed), the hand-over test passes.

- [ ] **Step 3: Implement the dispatch in `PhotoStore::store()` and `CreateImport::afterCreate()`**

The dispatch may happen inside the callers' transactions: on the database queue the job row rolls back with them, and a job that runs for a deleted original does nothing (Task 1).

- [ ] **Step 4: Run the new tests, then the whole suite**

Run: `docker compose exec app php artisan test --compact`
Expected: PASS. With `QUEUE_CONNECTION=sync`, tests that do not fake the queue now generate thumbnails during uploads; fix tests that list all files on the disk or assert nothing reported/pushed by narrowing them or adding `Queue::fake()`, never by changing production code to suit them.

- [ ] **Step 5: Pint, PHPStan, commit**

```bash
docker compose exec app ./vendor/bin/pint --dirty
docker compose exec app ./vendor/bin/phpstan analyse
git add app/Photos/PhotoStore.php app/Filament/Resources/Imports/Pages/CreateImport.php tests
git commit -m "Queue thumbnails as soon as photos are stored (TOR-19)"
```

### Task 3: Workers, panel background and docs

**Files:**
- Modify: `docker/supervisor/prod.conf`, `docker/supervisor/dev.conf`
- Modify: `resources/css/item-grid.css` (and the stylesheet of any other thumbnail place the implementer covers)
- Modify: `CLAUDE.md`, `docs/api-handover.md`

**Interfaces:**
- Consumes: queue name `thumbnails` (Task 1).

- [ ] **Step 1: Add the thumbnail workers**

Both files get a program next to `[program:queue]`:

```ini
[program:thumbnails]
command=php artisan queue:work --queue=thumbnails --tries=1 --timeout=90 --max-time=3600
process_name=%(program_name)s_%(process_num)s
numprocs=2
directory=/app
autostart=true
autorestart=true
priority=25
stopwaitsecs=120
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

In `dev.conf` the command is `php artisan queue:listen --queue=thumbnails --tries=1 --timeout=90`. The existing `queue` programs stay on `default`.

- [ ] **Step 2: Verify the workers**

Run: `docker compose build app && docker compose up -d app && docker compose exec app supervisorctl status`
Expected: `thumbnails:thumbnails_0` and `thumbnails:thumbnails_1` `RUNNING` next to `queue`.
Then upload one photo through "Erfassen" at http://localhost:8000 and check that `storage/app/private/thumbs/…` appears within seconds (`docker compose logs app` shows the job `DONE`).

- [ ] **Step 3: Gray background behind thumbnails in the panel**

In `resources/css/item-grid.css` give `.tory-item-grid .fi-ta-image img` `background: var(--gray-100)` and under `.dark` `var(--gray-800)`, matching `.fi-ta-placeholder`. Same for the other thumbnail places where cheap (imports table, view-page strips, `ItemPhotos`, bulk import review). Check a placeholder tile in light and dark mode with playwright-cli (http://localhost:8000; rename a file under `storage/app/private/thumbs/` to make one missing).

- [ ] **Step 4: Update the docs**

- `CLAUDE.md`, Gotchas: replace the sentence starting "only one thumbnail is generated at a time …" up to "… still missing." with: thumbnails are made by `GenerateThumbnail` on queue `thumbnails` (two workers) when photos are stored and when a request finds one missing; meanwhile the routes answer a placeholder PNG (`no-cache`); a photo that cannot be thumbnailed is remembered in the cache (`thumbnail-failed:<path>`) and keeps the placeholder until the cache is cleared.
- `CLAUDE.md`, Invariants "Queue timing": add `GenerateThumbnail` uses 1 and 60 on queue `thumbnails` (two workers, `--timeout=90`).
- `CLAUDE.md`, Running the app: supervisord also runs two thumbnail workers.
- `CLAUDE.md`, Architecture map "Items and photos": mention `GenerateThumbnail`.
- `docs/api-handover.md:338`: replace "If a thumbnail cannot be made, the original is returned instead, with the original's content type (not WebP)." with: until the thumbnail exists (right after an upload, or for good if the photo cannot be thumbnailed) the URL returns a 400×300 placeholder PNG with `Cache-Control: no-cache`; load it again later.

- [ ] **Step 5: Commit**

```bash
git add docker/supervisor resources/css CLAUDE.md docs/api-handover.md
git commit -m "Run two thumbnail workers and document background thumbnails (TOR-19)"
```
