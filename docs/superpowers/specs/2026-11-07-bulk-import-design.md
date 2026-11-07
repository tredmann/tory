# Bulk import — design

Date: 2026-11-07. Status: approved. Ticket: TOR-10. Vocabulary: `CONTEXT.md`.

## Intent

Today every thing goes through "Erfassen" on its own: pick a spot, upload its 1–6 photos, wait. Emptying a box of twenty things means twenty rounds. The user wants to photograph many things in one go, upload all photos at once and let the system work out which photos belong to the same thing. Because that guess will sometimes be wrong, the user checks and corrects the groups before anything is created. After that, each group continues as a normal import, with extraction and "Übernehmen" as today.

The user will mostly do this at a desktop computer.

## Goals

### Starting

- A second action **"Viele erfassen"** sits next to "Erfassen" on the dashboard ("Übersicht") and on the import list. There is no navigation entry of its own.
- The upload page asks for **one spot** for the whole bulk import, preselected with the user's `last_spot_id` (the shared `SpotSelect`), and for the photos.
- **At most 100 photos** per bulk import. More are rejected with a German message; the user starts a second bulk import for the rest.
- Same file rules as "Erfassen" and the item photo field: JPEG, PNG, WebP or GIF, at most 12 MB per file, shrunk in the browser to fit 2048 × 2048 px (never enlarged, GIFs untouched), uploaded one at a time through Livewire. The server checks type and size again.
- A button such as "Gruppieren" stores the bulk import and starts grouping in the background. It is disabled while uploads are running. The chosen spot becomes the user's `last_spot_id`.

### Grouping (background)

1. **Order by time.** Each photo gets a capture time from, in this order: the EXIF capture time (`DateTimeOriginal`), the file's modification date as the browser reports it (`File.lastModified`), the file name. Photos are sorted by that time, then by file name.
2. **Time groups.** A gap of more than **30 seconds** between two consecutive photos starts a new group. These groups are saved before the AI step, so they survive any failure of it.
3. **AI check of neighbouring photos.** The AI walks through **all** photos in time order in overlapping windows of consecutive photos and decides, for every pair of neighbouring photos, whether a new thing begins between them. This also finds a boundary inside a long time group, for example when a box is photographed quickly without pauses (**user's decision**, accepting more AI calls). Where the AI has decided a pair, its answer replaces the time boundary; the time groups only remain where the AI did not run. The result is applied automatically; the review page marks a card whose shape differs from the time groups ("KI: zusammengelegt", "KI: aufgeteilt").
4. If the AI step fails or there is no usable AI, the boundaries decided so far stay, the time groups remain for the rest, and the review page shows a German note, using the same fixed sentences as imports where the failure is one the user can fix on "Eigene KI" (`ProviderFailureReason`), and a general sentence otherwise.

While grouping runs, the bulk import shows "Wird gruppiert" and its page polls. Afterwards it shows "Bereit zur Prüfung".

### Review page

The user chose this layout from three sketches (cards; a single film strip with dividers; group list beside a large group view):

- At the top a bar with the spot as `{code} · {name}` (shown, not editable), "Löschen" and the confirm button with the count of imports it will create ("12 Importe anlegen").
- Below it the groups as cards, one under the other, in time order. Each card has a header with "Gruppe {n} · {count} Fotos", the AI mark if any, and on the right "Mit nächster zusammenlegen ↓" (not on the last card). Under the header its photos as square thumbnails in a wrapping row.
- **Move:** drag a photo into another card, at any position. Drag and drop is the main way; it must work with a mouse and must not break on a touchscreen, but it need not be comfortable there.
- **Split:** a small scissors (✂) sits in the gap between every two photos of a card; a click splits the card there, and the photos after the cut become a new card right below.
- **Merge:** "Mit nächster zusammenlegen" joins the card with the card below; the photos of the lower card follow those of the upper one.
- **Discard:** ✕ in the corner of a photo marks it as discarded; it greys out in place and shows "rückgängig", which restores it. A discarded photo stays in its card, moves and splits with it, and can be dragged like any other. It does not count towards the card's photo count or the 20-photo limit and goes into no import.
- A card disappears only when it has no photos at all, discarded or not. A card whose photos are all discarded stays visible (so they can be restored) and creates no import; the confirm count leaves it out.
- A card with **more than 20 remaining photos** is marked in red with a German hint, and the confirm button is disabled until it is split or reduced. With no remaining photo in any card, confirm is disabled too.
- Every change is saved right away, so the user can leave and come back later and find the groups as they left them.

### Confirm

- "Importe anlegen" creates one `Import` per card, in card order, as `pending`, with the bulk import's spot and the current user, and dispatches `ExtractImport` for each, exactly as "Erfassen" does.
- The bulk import is gone afterwards. The user lands on the import list and continues as today with "Übernehmen" on each import.
- Discarded photos are deleted.

### Keeping and deleting

- A bulk import stays stored until it is confirmed or deleted.
- Open bulk imports appear with their status on the dashboard next to the open imports (the `OpenImportsWidget` area) and on the import list; a click opens the review page (or the waiting state while grouping runs).
- "Löschen" on the review page removes the bulk import and all its photo files.

### Photo limit raised to 20

The limit for one import and one item rises from 6 to **20 everywhere**: "Erfassen" (`ImportForm`), the REST API (`StoreImportRequest`), the item photo field (`Item::changePhotos()`, `resources/js/item-photos.js`, its messages) and `CONTEXT.md` ("1–6 photos"). The lower limit stays 1.

The REST API takes all photos of an import in one request, and PHP (`docker/php.ini`) and the production gateway allow 80 MB per request. These limits stay (**user's decision**). Instead `StoreImportRequest` gets a rule for the **total size of all photos**, safely below 80 MB, with a German message, so the mobile app has to shrink photos as the panel does. The panel is unaffected because it uploads one photo at a time.

## Non-goals

- Bulk import through the REST API
- A spot per group, or changing the spot after upload
- More than 100 photos per bulk import
- Reordering photos inside an import beyond what moving between cards gives
- Learning from corrections, or tuning the gap per user
- A navigation entry for bulk imports

## Anti-goals

These would be failures even if the feature appeared to work:

- A photo file is lost: deleted while still part of a card or an import, or orphaned by a confirm that half went through.
- A confirm creates imports twice (double click, second tab), or creates some imports and keeps the bulk import too.
- An AI failure leaves the bulk import stuck on "Wird gruppiert" or loses the time groups.
- The bulk import silently spends money: no AI call happens after "Gruppieren" except the neighbour check, and none on the review page.
- The review page loses the user's corrections when they reload or come back.

## Constraints

- **User's decisions:** review before creating (not direct creation); time order plus an AI check of neighbouring photos, including inside long time groups (not AI over all photos at once, not time only); one spot for the whole bulk import; missing EXIF falls back to modification date, then file name; the limit rises to 20 everywhere; panel only; bulk imports are stored and can be continued; at most 100 photos; review layout as cards with drag and drop; entry "Viele erfassen", no navigation entry; the API keeps the 80 MB request limit and gets a total-size rule.
- **New dependency approved:** the npm package `exifr`, to read the capture time in the browser. The canvas resize drops EXIF, so the time must be read **before** shrinking and sent along with the upload. The PHP `exif` extension in the image cannot help, because the server never sees the original.
- The browser's capture time is only used for ordering, so trusting it is harmless; the server still validates it as a date or null.
- Photo files belong to exactly one bulk import, import or item at a time (project rule, extended). Confirm hands the files over to the new imports without copying or deleting them.
- Photos stay on the private `local` disk under `imports/`, shown through temporary signed links, as everywhere else.
- UI copy is German, light and dark mode, plain CSS (Filament's precompiled theme has no uncompiled Tailwind classes), loaded through the existing `@vite` render hook.
- UUID primary key (`HasUuids`); status enum in `app/Enums/` implementing `HasLabel`/`HasColor`.
- Queue timing invariant: every job timeout < 210 < 300, `$tries = 1` for jobs that call the AI. With up to 100 photos the AI step will not fit in one run.
- The AI step uses `AiProvider::active()` read fresh in each job run, else the `.env` fallback (`config('ai.inventory.*')`), like `ExtractImport`. Failures on a provider are wrapped in `ProviderFailure`.
- Resource pages use the traits in `app/Filament/Resources/Concerns/` (breadcrumb starts with the house).

## How it works

Starting points for the builder. Points marked "my call" were decided by me, not the user.

- **Model.** A `BulkImport` (UUID, `user_id`, `spot_id`, status, the photos with their capture time, the groups in order, which groups the AI shaped, discarded photos, and the AI note). The exact columns and JSON shapes are the builder's.
- **Upload.** A custom Alpine upload field modelled on `itemPhotos` (`resources/js/item-photos.js`): read time with `exifr`, shrink on a canvas, upload one at a time through Livewire's `$upload`, counter store to disable the button. The shrink code should be shared rather than copied. Filament's `FileUpload` is not usable here because its resize also drops EXIF and it has no hook for the time.
- **Grouping job** (my call on the shape). `GroupBulkImport`, `$tries = 1`, `$timeout = 200`. It saves the time groups first, then walks the windows; after about 150 seconds it saves its progress and dispatches itself with a cursor, as `ReembedItems` does. Its `failed()` hook marks the bulk import ready with the general note, so it can never stay on "Wird gruppiert".
- **AI check** (my call on the shape). A new structured-output agent next to `InventoryExtractor`. Each call gets a window of **up to 8 consecutive photos** in time order and returns, for each neighbouring pair in the window, whether a new thing begins between them. Windows overlap so that every pair has at least one photo of context on each side, and each pair is decided by exactly one window (the one where it sits nearest the middle). The cap of 8 images per call also stays within vendors that limit images per request. On the first failure the job stops and keeps what it has. With 100 photos this is about 15–20 calls.
- **Review state.** Changes go to the server immediately (Livewire actions) and are applied to the row under a lock, like `Item::changePhotos()`, so a second tab cannot resurrect a discarded photo or duplicate one. Drag and drop with Livewire 4's own `wire:sort` if it supports moving between lists; otherwise the builder proposes an alternative before adding any dependency.
- **Confirm.** In one transaction: lock the row, check every card has 1–20 photos, create the imports, delete the bulk import row with a query-builder delete (no model event, so its files are kept), set `last_spot_id`. After the commit: dispatch `ExtractImport` per import and delete the discarded files (a failed deletion is reported, not undone). When the row is already gone, answer 404 and create nothing, like a second "Übernehmen".
- **Delete.** A plain model delete removes all files of the bulk import, discarded ones included.

## Calls I made

- The gap is **30 seconds**, a constant. The user has not tried this way of photographing yet; AI check and review correct a wrong guess.
- AI suggestions are applied automatically and only marked; there is no "accept suggestion" step, since the review page is that step.
- Windows of at most 8 photos per AI call.
- Discarding is reversible until confirm; a discarded photo stays in its card, and its file is deleted only on confirm or delete.
- The spot is shown on the review page but cannot be changed there.
- Grouping states: "Wird gruppiert" and "Bereit zur Prüfung". There is no failed state; an AI failure is a note on a ready bulk import.

## Risks

- **Cost.** 100 photos mean about 15–20 AI calls of up to 8 images for the check, then one extraction per import. With 20 photos per import, extraction and "Neu generieren" cost more per call than today.
- **Small local models.** Whether `gemma4` on Ollama handles 20 images in one extraction, and the neighbour question at all, must be checked with `extraction:eval` (a case with many photos) and a live run before release.
- **Vendor limits.** The builder must confirm each vendor (Claude, OpenAI, Mistral, Ollama) accepts 20 images in one extraction request, both by image count and by total request size (photos uploaded through the API may be larger than the panel's shrunk ones); if one does not, report back before working around it.

## Testing

Follow `testing-best-practices`, feature tests with `Storage::fake('local')`, `Queue::fake()` where the job is not under test, and a faked agent for the AI check. They must cover:

- Time grouping: order by capture time with the fallbacks, the 30-second gap, ties by file name.
- The AI check merges and splits as answered, including a split inside one long time group, decides every pair exactly once across overlapping windows, marks the cards, and on failure keeps what it decided plus the time groups with the right note (provider reason and general).
- The API rejects photos over the total size with a German message, and accepts 20 photos within it.
- The job continues with a cursor and `failed()` leaves a ready bulk import.
- Upload limits: 100 photos, file type and size on the server, invalid paths rejected.
- Review actions: move, split, merge, discard and undo, a card with only discarded photos stays and creates no import, an empty card vanishes, more than 20 remaining photos blocks confirm; a stale second tab cannot bring back a discarded photo.
- Confirm: one import per card in order with spot and user, `ExtractImport` dispatched per import, `last_spot_id` set, discarded files deleted, kept files untouched, the bulk import gone; a second confirm answers 404 and creates nothing.
- Delete removes every file.
- The limit of 20 on "Erfassen", the API and the item photo field (existing tests that assert 6 are adapted, not weakened).
- Dashboard and import list show open bulk imports.

The browser part (EXIF reading, canvas, drag and drop) needs a manual check in a desktop browser with real phone photos, including some without EXIF.

## Left to the builder

- Columns and JSON shapes of `BulkImport`, the resource and page layout, the URLs.
- How the dashboard and import list show bulk imports next to imports (same widget or a second one).
- The agent's prompt and schema, judged with real photos.
- Exact German texts beyond those named here.
- Updating `CLAUDE.md` (architecture, queue timing invariant, the 1–6 mentions) and `CONTEXT.md` (a "Bulk import" term, the import's photo count).
