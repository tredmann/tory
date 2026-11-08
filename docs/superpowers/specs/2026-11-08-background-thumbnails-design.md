# Background thumbnails — design

Date: 2026-11-08. Status: approved. Ticket: TOR-19. Vocabulary: `CONTEXT.md`. Builds on `2026-11-07-photo-endpoint-design.md`.

## Intent

After the user uploaded many items through the API, the first page that showed them loaded very slowly. Thumbnails are made only when a photo is first requested, one at a time across the server, and every request that finds the lock busy gets the original (up to 12 MB, `no-cache`). A list of fresh photos therefore downloads mostly originals.

Thumbnails should be made in the background as soon as photos are stored, and a missing thumbnail should be answered with a small placeholder, never with the original.

## Goals

- Storing photos queues their thumbnails, wherever the photos come from: "Erfassen" in the panel, the API (`POST /api/v1/imports`, `POST /api/v1/items`), bulk import, photo editing of an item.
- `/photos/thumb/…` (panel) and `/api/v1/{imports,items}/{id}/photos/{photo}/thumb` (API) answer a missing thumbnail at once with a placeholder image, `Cache-Control: private, no-cache`. They never return the original and never generate a thumbnail inside the request.
- A request that finds a thumbnail missing also queues it. Photos already on the server without a thumbnail are thereby made as they are viewed; there is no backfill command.
- Thumbnail jobs run on their own queue, `thumbnails`, with two dedicated worker processes, so they neither wait behind object recognition nor delay it.
- The real thumbnail appears on the next page load (the user does not want it swapped in live).

## Non-goals

- BlurHash, ThumbHash or any blurred preview. The blur would have to be computed from the original, which is exactly the expensive step, so it would not exist earlier than the thumbnail. Discussed and declined.
- Swapping placeholders for thumbnails without a reload (polling, Livewire refresh).
- A backfill command for all existing photos.
- Changes to thumbnail size, format, orientation handling or storage path (`thumbs/<original path>.webp`).

## Anti-goals

- A request for a thumbnail must never decode an original. That is the whole point.
- A photo that cannot be thumbnailed must not queue a job and report an exception on every page view.

## Current state

- `App\Photos\Thumbnails::for()` checks the file, takes the cache lock `photo-thumbnails`, generates and returns the path, or `null` when the lock is busy or generation failed.
- `App\Photos\PhotoResponder::thumbnail()` serves the thumbnail (`immutable`) or, on `null`, the original (`no-cache`). Panel `PhotoController` and `Api\V1\PhotoController` both go through it.
- Writers: `ImportForm`'s `FileUpload` (Livewire stores the file; `CreateImport::afterCreate()` dispatches `ExtractImport`), `BulkImport::start()`, `Item::changePhotos()`, `Api\V1\ImportController::store()`, `Api\V1\ItemController::store()`. All but the panel form call `PhotoStore::store()`.
- Queue: database driver, one worker (`docker/supervisor/prod.conf`: `queue:work --tries=3 --timeout=210 --max-time=3600`; `dev.conf`: `queue:listen --tries=3 --timeout=210`), queue `default`. Cache store: database.
- Tiles are 4:3 with `object-fit: cover`; the grid's "no photo" placeholder uses `--gray-100` (dark: `--gray-800`) with an icon in `--gray-500`/`--gray-400` (`resources/css/item-grid.css`).

## Design

### The job

- A new job (working name `GenerateThumbnail`, builder's call) makes the thumbnail of **one** photo path, using the existing generation code in `Thumbnails` (scale to 400 px, EXIF orientation, WebP, temp file then rename).
- On queue `thumbnails`. Unique per path while queued or running (`ShouldBeUnique`, `uniqueId` = path), so repeated requests for the same missing thumbnail queue it once.
- Does nothing when the original no longer exists (deleted, or the creating transaction rolled back) or the thumbnail already exists.
- `$tries = 1`, `$timeout = 60` (fits the queue-timing invariant: < 210 < 300). Generation failures are not transient (corrupt file, over `MAX_PIXELS`), so no retry.
- One job per photo, so the two workers share a large upload.

### Queuing on upload

- Every writer listed above queues the job for each photo it stored. For the paths through `PhotoStore::store()` the builder may put the dispatch there; the panel form has no such call, so `CreateImport::afterCreate()` queues for the import's photos.
- Hand-over (bulk import → import → item) queues nothing: the paths do not change, the thumbnails are already there or queued.

### Serving

- `Thumbnails::for()` no longer generates. It returns the thumbnail path when the file exists; otherwise it queues the job (unless the photo is marked as failed, below) and returns `null`.
- `PhotoResponder::thumbnail()` answers `null` with the placeholder (`private, no-cache`) instead of the original. The 404 for a missing original stays.
- The cache lock `Thumbnails::LOCK` goes away: with two workers there are at most two generations at once, which the user accepted (server has 16 GB; each worker may use up to 512 MB).

### Photos that cannot be thumbnailed

- When generation fails, the job reports the exception once and remembers the failure for that path in the cache (forever). `Thumbnails::for()` does not queue a job for a remembered path, so such a photo shows the placeholder for good, as agreed with the user.
- *Writer's call:* the cache rather than a marker file or column, since it needs no migration and a cache clear gives every failed photo one more attempt.
- A job that dies without reaching that catch (60 s timeout, `memory_limit`, killed worker) is marked the same way in the job's `failed()` method, otherwise such a photo would be queued again on every view. *Writer's call:* a good photo hit by a hard kill would then keep the placeholder until the cache is cleared; that is rare, because the worker's stop grace (below) lets a running job finish on every normal restart or deploy, and `MAX_PIXELS` keeps decoding within memory.
- `PhotoStore::delete()` need not clear the mark: file names are random and never reused.

### The placeholder

- One static PNG in the repository (location builder's call, e.g. `resources/images/`), 4:3 like the tile, transparent background with a simple image symbol in a mid gray that reads on both light and dark backgrounds. Panel and API serve the same file.
- *Writer's call:* PNG rather than SVG, because API clients such as a native app often cannot show SVG; transparent rather than gray, because one file must suit both themes.
- In the panel the image elements that show thumbnails get the tile background (`--gray-100`, dark `--gray-800`) in CSS, so the transparent placeholder appears as a gray tile with a symbol, matching the existing "no photo" look. Where exactly (grid tiles, imports table, view-page strips, `ItemPhotos`, bulk import review) is the builder's call; at least the item grid.

### Workers

- `docker/supervisor/prod.conf`: a second program, e.g. `[program:thumbnails]`, `queue:work --queue=thumbnails --tries=1 --timeout=90 --max-time=3600`, `numprocs=2` with a `process_name` that includes `%(process_num)s`. The existing `queue` program keeps listening on `default` only.
- `docker/supervisor/dev.conf`: the same with `queue:listen`.
- Worker `--timeout` above the job's 60 s and below `retry_after` (300).
- `stopwaitsecs` safely above the worker `--timeout` (e.g. 120), so a restart or deploy never kills a running job and marks a good photo as failed. The app container's 4-minute stop grace already covers it.

### Docs

- `CLAUDE.md`:
  - Gotcha "only one thumbnail is generated at a time … shows the original (`no-cache`) …": replace with the new behavior (queued on upload and on a miss, placeholder meanwhile, failed photos keep the placeholder).
  - Queue-timing invariant: add the thumbnail job (1 try, 60 s) and the `thumbnails` queue with two workers.
  - Running the app: `supervisorctl status` now shows the thumbnail workers.
- `docs/api-handover.md`: a thumbnail URL may return the placeholder PNG (`no-cache`) until the thumbnail exists; reload later.
- `2026-11-07-photo-endpoint-design.md` stays as the record of its decisions; this document supersedes its "generated on first request" and "falls back to the original" parts.

## Testing

Feature tests (Pest, `Storage::fake('local')`, `Queue::fake()` except where the job itself is under test):

- Each writer queues one job per stored photo on queue `thumbnails`: panel "Erfassen", API import, API item, bulk import start, `Item::changePhotos()` (only for newly added photos).
- Thumbnail route (panel and API) with a missing thumbnail: answers the placeholder PNG with `no-cache`, queues the job, does not create the thumbnail.
- With an existing thumbnail: serves it `immutable`, queues nothing.
- A path marked as failed: placeholder, nothing queued.
- The job: creates the thumbnail (size and WebP as today); no-op when the original is missing or the thumbnail exists; on a corrupt image marks the path as failed and creates nothing; `failed()` marks the path as failed.
- Existing `ThumbnailsTest` and photo endpoint tests are adapted (the lock test goes away; "falls back to the original" becomes "answers the placeholder").

## Left to the builder

- Names of the job and the placeholder file.
- Whether the upload dispatch lives in `PhotoStore::store()` or at each writer.
- The cache key format for failed paths.
- Which panel image elements get the gray background beyond the item grid.
