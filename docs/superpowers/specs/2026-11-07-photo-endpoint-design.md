# Photo endpoint and thumbnails — design

Date: 2026-11-07. Status: draft. Ticket: TOR-13. Vocabulary: `CONTEXT.md`.

## Intent

Every list in the panel loads each photo in full size (up to 2048 px from the browser, larger through the API). The page itself appears quickly, but the photos trickle in one after another. Photos should come from an endpoint of their own that serves either the original or a small thumbnail, and every place that shows a photo small should load the thumbnail.

New photos should also stop piling up in one flat folder: they go into year and month folders. Existing photos stay where they are.

## Goals

- A panel route serves photos to logged-in, verified users through the session. It replaces the temporary signed URLs in the panel.
- The same route serves a thumbnail through a path prefix, not a query parameter:
  - original: `/photos/2026/11/<name>.jpg`
  - thumbnail: `/photos/thumb/2026/11/<name>.jpg`
- Thumbnails: 400 px on the longest edge, WebP. Created with GD on first request, stored, and served from storage after that. Existing photos get thumbnails this way, without a backfill.
- Photos shown small load the thumbnail; the slideshow loads originals.
- The REST API also offers thumbnails.
- New photos are stored under `photos/YYYY/MM/`, by upload date.

## Non-goals

- Spatie Media Library. The user chose the lean variant: the `photos` path lists on `BulkImport`, `Import` and `Item` stay as they are, and so does the hand-over between them.
- Moving existing photos out of `imports/`.
- More than one thumbnail size, responsive `srcset`, or format negotiation.
- Generating thumbnails at upload time or in a queue job.

## Anti-goals

- The route must not become a way to read arbitrary files from `storage/app/private` (evals, provider exports, Livewire temp files). It serves only files below `imports/` and `photos/`, and rejects anything else, including `..` segments.
- A tile must never break because its thumbnail cannot be made: if generating fails, the route answers with the original (and reports the error) rather than an error image.

## Current state

- All photos are on the `local` disk (`storage/app/private`, `serve: true`) in `imports/<random>.<ext>`. Paths are stored as JSON lists; hand-over passes the same paths on, so a path never changes.
- Writers: `ImportForm` (`FileUpload::directory('imports')`), `BulkImport::start()`, `Item::changePhotos()`, `Api\V1\ImportController`, `Api\V1\ItemController` (`->store('imports', 'local')`).
- Deleters: model `deleted` events of `Import`, `Item`, `BulkImport`; discarding in `BulkImport`; removing in `Item::changePhotos()`; clean-up after failures in `BulkImport::start()`, `Item::changePhotos()`, `ItemController`.
- Panel display: `ImageColumn`/`ImageEntry` with `disk('local')->visibility('private')` in `ItemGrid`, `ImportsTable`, `ImportInfolist`, `ItemInfolist`; temporary signed URLs in `ItemPhotos::getPhotos()` and `ViewBulkImport::cards()`. `PhotoSlideshow::imgAttributes()` builds the slideshow URLs through the component's `getImageUrl()`.
- API: `GET /api/v1/{imports,items}/{id}/photos/{file name}` (`PhotoController`), URLs built by `PhotoUrls`; responses carry `photos` as a list of URL strings.

## Design

### Storage layout

- New originals: `photos/YYYY/MM/<random>.<ext>` on the `local` disk, where `YYYY/MM` is the upload date. File names stay Laravel's random hash names, so a file name is unique across all folders (the API relies on that).
- Thumbnails: `thumbs/<original path without extension>.webp`, e.g. `thumbs/photos/2026/11/abc.webp`, `thumbs/imports/abc.webp`. A thumbnail belongs to its original's path, so hand-over needs no change.
- One small class owns the layout (working name `App\Photos\PhotoStore`, builder's call): the directory for new photos, the thumbnail path for an original, creating a thumbnail, and deleting originals together with their thumbnails. Every writer and deleter listed above goes through it instead of naming `imports` or calling `Storage::delete()` on photo paths itself.

### Panel route

- Registered in `AdminPanelProvider::authenticatedRoutes()` next to `/theme`, so Filament's auth middleware applies and guests are sent to the login. That group does **not** check email verification in Filament 5 (pages add it per route, and `User::canAccessPanel()` returns `true`), so the photo route adds Filament's email-verified middleware explicitly.
- URL to storage path:
  - `/photos/{rest}` → `photos/{rest}` when `rest` starts with a four-digit year, `imports/{rest}` stays `imports/{rest}` when it starts with `imports/`. In other words, the URL of an original is `/photos/` plus its storage path with a leading `photos/` dropped. Old photos therefore live at `/photos/imports/<name>.jpg`.
  - `/photos/thumb/{rest}` → the thumbnail of the same original.
  - Anything that does not map to an existing file below `photos/` or `imports/` answers 404.
- Responses send `Cache-Control: private, max-age=31536000, immutable`, since a path's content never changes. The correct `Content-Type` comes from the file.
- The route does not check which record owns the photo: every verified user sees every record (one household).

### Thumbnails

- Created on the first request for `/photos/thumb/…` when the thumbnail file does not exist yet, then stored and served.
- 400 px on the longest edge, never upscaled, WebP. GIFs use their first frame.
- EXIF orientation is applied before scaling (API uploads are not resized by the browser and may carry a rotation flag). The `exif` extension is present in the image.
- Written to a temporary name and renamed into place, so two parallel first requests cannot serve a half-written file.
- Only one thumbnail is generated at a time across the server (a Laravel cache lock; the other requests wait for it and then check again whether the file now exists). The user accepted that the first visit of a list with many photos without thumbnails is slower in exchange for bounded memory: each generation decodes a full original, about 16 MB in GD for a browser-resized 2048 px photo and 200 MB or more for a large API upload.
- GD with the 512 MB memory limit handles the 12 MB upload limit; the builder confirms this with a large test image.
- If generating fails (corrupt file, unsupported data), the route serves the original with `Cache-Control: private, no-cache` and reports the exception. Nothing is stored, so the next request tries again; a fixed cause takes effect without clearing caches. (Writer's call: corrupt photos are rare, so retrying costs little.)

### Where thumbnails and originals are used

| Place | Loads |
|---|---|
| `ItemGrid` tiles (items, spot view, dashboard widgets) | thumbnail |
| `ImportsTable` column | thumbnail |
| Photo strip on the item and import view pages (`ImportInfolist`, `ItemInfolist`, 160 px high) | thumbnail |
| `ItemPhotos` (photo editing) | thumbnail |
| Bulk import review page | thumbnail |
| Slideshow (`PhotoSlideshow` data attributes) | original |

The photo strip on the view pages is 160 px high and opens the slideshow on click, so it takes thumbnails and the slideshow brings the originals (confirmed by the user).

How Filament's `ImageColumn`/`ImageEntry` are pointed at the new URLs is left to the builder (search the Filament docs first); the slideshow must keep receiving original URLs while the `<img>` shows the thumbnail. The temporary signed URLs and the "tab open past expiry needs a reload" gotcha go away in the panel.

### Upload directory

- `ImportForm`'s `FileUpload` stores into the dated directory instead of `imports`.
- `BulkImport::start()`, `Item::changePhotos()`, `ImportController`, `ItemController` store into the dated directory.

### Deleting

Every place that deletes photo files today deletes their thumbnails too (through the one class). A thumbnail that does not exist is not an error. Hand-over (query-builder delete of the old row) still deletes nothing.

### REST API

- New routes next to the existing ones, with a path suffix:
  - `GET /api/v1/imports/{import}/photos/{photo}/thumb`
  - `GET /api/v1/items/{item}/photos/{photo}/thumb`
  Same lookup as today (file name must be one of the record's photos), same thumbnail creation as the panel.
- `ImportResource` and `ItemResource` get a new field `thumbnails`: a list of URLs in the same order as `photos`. `photos` stays unchanged, so existing clients keep working.
- `docs/api-handover.md` documents `thumbnails` (use it for tiles) and the new routes; Scramble picks them up for `/docs/api`.

### Docs

- `CLAUDE.md`: architecture line for photos (endpoint, thumbnails, layout); remove the gotcha about signed thumbnail URLs for the panel and keep the API sentence; mention that photo files are written and deleted only through the photo class.
- `CONTEXT.md` if a term (thumbnail, "Vorschaubild") is added.

## Testing

Feature tests (Pest, `Storage::fake('local')`), at least:

- Original and thumbnail served to a verified user; guest redirected to login; unverified user refused.
- Old path (`imports/…`) and new path (`photos/YYYY/MM/…`) both served.
- Paths outside `imports/` and `photos/`, `..` segments and missing files answer 404.
- First thumbnail request creates the file with the expected size and WebP type; second request serves the stored file without regenerating.
- A corrupt image falls back to the original.
- Each writer stores under `photos/YYYY/MM/` (with a fixed `now()`).
- Each deleter removes thumbnails as well; hand-over keeps them.
- API: `thumbnails` field in import and item responses, thumbnail routes with bearer auth, wrong file name 404.
- Panel pages render thumbnail URLs for tiles and original URLs in the slideshow attributes.

## Left to the builder

- Name and namespace of the photo class and the controller.
- How the Filament image components get their URLs.
- Whether `spatie/image` or plain GD does the scaling. A new dependency needs the user's approval; plain GD avoids that question.
