# Photo Slideshow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Larger thumbnails in the three lists and on the two view pages, and a click on a thumbnail opens all photos of that record in a GLightbox slideshow.

**Architecture:** PHP puts the photo URLs and the clicked position on each `<img>` as data attributes, through one small helper used by all five surfaces. One script, loaded into the panel with `@vite` through a render hook, listens for clicks on `document` in the capture phase, so it survives Livewire re-renders and beats the row link.

**Tech Stack:** Laravel 13, Filament 5.11, Livewire 4, Pest, Vite, GLightbox 3.3.1.

**Spec:** `docs/superpowers/specs/2026-11-06-photo-slideshow-design.md`

## Global Constraints

- Everything PHP runs in the `app` container, everything npm in the `vite` container.
- `glightbox` 3.3.1 is the only new dependency.
- Thumbnail height: 80 px in lists, 160 px on view pages. Width follows the photo's proportions.
- Lists keep `limit(1)`; the slideshow still holds all photos of the record.
- GLightbox options: `loop: true`, `zoomable: false`. Everything else stays at its default.
- Touch behaviour is accepted as it is (spec, "Known exceptions on touch devices"). Write no touch code.
- Photo links stay Filament's `getImageUrl()`. Do not add routes or change how links are signed.
- Commit messages are plain sentences as in `git log`, ending with the attribution line from the session.
- Run Pint and PHPStan before each commit.

## Review Focus

1. A photo's file is missing from the disk: the page still renders, the slideshow holds the remaining photos, and the positions match them.
2. A photo path contains characters such as `"`, `&` or a space: the attribute stays valid HTML and decodes to the right URLs.
3. A record without photos: the page renders without an error and without slideshow attributes.
4. The import view page re-renders through its 5 s poll: the attributes are still present afterwards. The open slideshow surviving a poll is a manual check, because it lives outside the Livewire component.
5. A click on a list photo must not also open the detail page, and a click on the rest of the row must still do so. The suite has no browser tests, so the rendered link is tested and the click is a manual check.

---

### Task 1: Larger thumbnails with slideshow data

**Files:**
- Create: `app/Filament/Schemas/PhotoSlideshow.php`
- Modify: `app/Filament/Resources/Items/Tables/ItemsTable.php:31-35`, `app/Filament/Resources/Imports/Tables/ImportsTable.php:19-23`, `app/Filament/Resources/Spots/RelationManagers/ItemsRelationManager.php:22-26`, `app/Filament/Resources/Items/Schemas/ItemInfolist.php:22-26`, `app/Filament/Resources/Imports/Schemas/ImportInfolist.php:24-28`
- Test: `tests/Feature/Panel/PhotoSlideshowTest.php`

**Interfaces:**
- Produces: `<img data-slideshow="{JSON array of URLs}" data-slideshow-index="{int}">`. Task 2 reads exactly these two attributes.
- Produces: `PhotoSlideshow::imgAttributes(ImageColumn|ImageEntry $component, array $paths, string $clickedPath): array`

- [ ] **Step 1: Create the branch and commit the design documents**

Create `feature/photo-slideshow` from `master`, then commit the spec and this plan on it.

- [ ] **Step 2: Write the failing tests**

Use `Storage::fake('local')`, `Queue::fake()` and `actingAs(User::factory()->create())` in `beforeEach`. Put the files on the fake disk, because Filament returns no URL for a missing file. Read the attributes with a file-local helper that parses `->html()` with `DOMDocument` (`@loadHTML`) and returns, per `img[@data-slideshow]`, the decoded URL list and the index.

Run the first four tests against each surface through a dataset: `ListItems`, `ListImports`, `ItemsRelationManager` (`['ownerRecord' => $spot, 'pageClass' => ViewSpot::class]`), `ViewItem`, `ViewImport` (`['record' => $id]`).

```php
test('a thumbnail carries all photos of its record')
// three stored photos: exactly 1 img with data-slideshow on a list, 3 on a view page
// URL list has 3 entries, in photo order, containing a.jpg, b.jpg, c.jpg
// on a view page the indexes are [0, 1, 2]; on a list the index is 0

test('a photo whose file is missing is left out')
// photos a, b, c with only b and c stored
// URL list has 2 entries; c has index 1; a's img has no data-slideshow

test('a path with special characters stays valid')
// stored path 'imports/a "b" & c.jpg': the DOM parses, and the decoded URL list has 1 entry

test('a record without photos renders without slideshow data')
// photos [] : assertOk, no img[@data-slideshow]

test('view page thumbnails are large and side by side')
// html contains 'height: 160px' and 'fi-wrapped', and does not contain 'fi-stacked'

test('list thumbnails are large')
// html contains 'height: 80px'

test('list rows still link to the detail page')
// ListItems, ListImports and the relation manager: html contains the record's view URL

test('the import view page keeps the slideshow data on a re-render')
// a running import: ->call('$refresh'), then the attributes are still present
```

- [ ] **Step 3: Run the tests and confirm they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/PhotoSlideshowTest.php`
Expected: FAIL, no `img[@data-slideshow]` found.

- [ ] **Step 4: Implement `PhotoSlideshow::imgAttributes()`**

Create it with `php artisan make:class`, then move it to `app/Filament/Schemas/`, next to `RegenerationEntries.php`.

- Map `$paths` through `$component->getImageUrl($path)`, keeping each path with its URL. Drop paths with a `null` URL (missing file).
- If `$clickedPath` was dropped, return `[]`.
- Otherwise return `data-slideshow` and `data-slideshow-index`.
- Wrap the JSON in `e()`: Filament merges these attributes with `escape: false`, so an unescaped `"` ends the attribute.

- [ ] **Step 5: Use it on all five surfaces**

Filament's closure injection resolves by parameter name or a single class type, not a union, so type the parameter per surface:

```php
->extraImgAttributes(fn (ImageColumn $column, Item $record, string $state): array => PhotoSlideshow::imgAttributes($column, $record->photos, $state))
```

- Lists: add `->imageHeight(80)`, keep `->limit(1)`.
- View pages: use `ImageEntry $entry`, remove `->stacked()`, add `->imageHeight(160)` and `->wrap()`.
- Use `Import` as the record type on the two import surfaces.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/PhotoSlideshowTest.php`
Expected: PASS. If an `imageHeight()` integer renders differently from `160px`, pass `'160px'` and `'80px'` as strings.

- [ ] **Step 7: Run the affected suites, Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel`, `docker compose exec app ./vendor/bin/pint --dirty --format agent`, `docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all pass.

- [ ] **Step 8: Commit**

Message: `Show larger thumbnails and give them the data for a slideshow`

### Task 2: The slideshow in the panel

**Files:**
- Create: `resources/js/slideshow.js`, `resources/css/slideshow.css`
- Modify: `package.json`, `package-lock.json`, `vite.config.js`, `app/Providers/Filament/AdminPanelProvider.php:43-46`, `tests/TestCase.php`, `CLAUDE.md` ("Assets/styling" paragraph)
- Test: `tests/Feature/Panel/PhotoSlideshowTest.php`

**Interfaces:**
- Consumes: `data-slideshow` and `data-slideshow-index` from Task 1.

- [ ] **Step 1: Write the failing test**

```php
test('the panel loads the slideshow script')
// $this->withVite(); write a temporary hot file containing 'http://vite.test';
// Vite::useHotFile($path); $this->get('/imports')
// assertOk, assertSee('resources/js/slideshow.js')
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/PhotoSlideshowTest.php --filter="slideshow script"`
Expected: FAIL, the response does not contain the script.

- [ ] **Step 3: Install the dependency and register the entry**

Run: `docker compose exec vite npm install --save-exact glightbox@3.3.1`

Add `resources/js/slideshow.js` to the `input` array in `vite.config.js`.

- [ ] **Step 4: Load it in the panel and keep tests free of Vite**

- Add a `PanelsRenderHook::HEAD_END` hook next to the existing `STYLES_AFTER` hook, rendering `Blade::render("@vite('resources/js/slideshow.js')")` as `HtmlString`.
- Call `$this->withoutVite()` in `TestCase::setUp()`. CI has no manifest and no hot file, so every panel page would fail otherwise.

- [ ] **Step 5: Write `resources/js/slideshow.js` and `resources/css/slideshow.css`**

The script imports `glightbox` and `glightbox/dist/css/glightbox.min.css`, plus `../css/slideshow.css`. It keeps one GLightbox instance with `loop: true` and `zoomable: false`. The essential logic:

```js
document.addEventListener('click', (event) => {
    const image = event.target.closest?.('img[data-slideshow]');
    if (!image) return;

    // Capture phase: the row link around the image must not navigate.
    event.preventDefault();
    event.stopPropagation();

    const urls = JSON.parse(image.dataset.slideshow);
    lightbox.setElements(urls.map((href) => ({ href, type: 'image' })));
    lightbox.open(null, Number(image.dataset.slideshowIndex));
}, true);
```

Position display: GLightbox has none. Append one element with the class `tory-slideshow-position` to `.gcontainer`, showing `{index + 1} / {total}`. Set it on the `open` event from the start index, and update it on `slide_changed` from `current.index`. Keep it empty when there is one photo. GLightbox already hides its arrows in that case.

The CSS sets `cursor: pointer` on `img[data-slideshow]` and places `.tory-slideshow-position` at the top left of the overlay, in light text above the slide. It must be plain CSS, because Tailwind classes that Filament has not compiled do not exist in the panel.

If `setElements()` or `open(null, index)` fails before the first `open()`, because the slide container is not built yet, create a new instance with `elements` and `startAt` per click instead, and say so in the task report.

- [ ] **Step 6: Run the test and the build**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/PhotoSlideshowTest.php`, then `docker compose exec vite npm run build`
Expected: tests PASS. The build succeeds, and `public/build/manifest.json` has an entry for `resources/js/slideshow.js`.

Run: `curl -s http://localhost:5173/resources/js/slideshow.js | head -5`
Expected: JavaScript from the dev server (`public/hot` holds `http://localhost:5173`).

- [ ] **Step 7: Update `CLAUDE.md`**

In "Assets/styling", state that the panel loads `resources/js/slideshow.js` through a `HEAD_END` render hook with `@vite`, that this makes a running `vite` service or a build necessary for panel pages outside tests, and that `resources/js/app.js` and `resources/css/app.css` remain unloaded. Add one short "Photos" clause on `PhotoSlideshow` and the data attributes to the "Items" paragraph.

- [ ] **Step 8: Run the whole suite, Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact`, `docker compose exec app ./vendor/bin/pint --dirty --format agent`, `docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all pass.

- [ ] **Step 9: Commit**

Message: `Open photos in a slideshow`

### Task 3: Hand-over for the manual check

**Files:** none.

- [ ] **Step 1: Ask the user to check by hand, and report the result**

The automation browser cannot load `localhost`, so the user does this, on a computer and on a real phone:

- List: a click on a photo opens the slideshow and the page stays on the list. A click elsewhere in the row opens the detail page.
- List after paging, filtering and searching: the photo click still works.
- View page: thumbnails are side by side and fully visible, and a click starts at the clicked photo.
- Computer: arrows and arrow keys wrap at both ends. X, Esc and a click beside the photo close. "2 / 5" follows along. A click on the photo does not zoom.
- One photo: no arrows and no position display.
- Import in "Läuft": an open slideshow stays open through the 5 s poll.
- Phone: swiping browses and stops at the ends. Pinch zooms. Tapping beside the photo does not close, while X and vertical swipe do.
- Production build: run `docker compose exec vite npm run build` and confirm the image's asset stage still builds, with `docker build --target prod -f docker/Dockerfile .`.

- [ ] **Step 2: Update TOR-1 after the user's check**

Update the ticket: the lists are in scope, the thumbnails get larger, the touch exceptions apply, and the "only logged-in users" note is replaced by the temporary signed links (spec, Constraints).
