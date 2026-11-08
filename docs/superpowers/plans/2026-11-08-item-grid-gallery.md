# Item Grid Gallery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Item tiles with several photos show up to three: hover strips on pointer devices, swiping on touch devices, dots on both, click opens the slideshow at the visible photo.

**Architecture:** `ItemGrid`'s `ImageColumn` renders up to three `<img>`. CSS turns `.fi-ta-image` into a one-photo-wide scroll-snap strip with dots; `resources/js/item-gallery.js` scrolls it from the pointer position and marks the active dot.

**Tech Stack:** Filament 5, plain CSS, vanilla JS through Vite, Pest on Postgres.

**Spec:** `docs/superpowers/specs/2026-11-08-item-grid-gallery-design.md`

## Global Constraints

- At most three photos per tile, no "+n" text. The imports list stays at one photo.
- The script listens on `document` only (delegated pointer events, `scroll` in the capture phase) so Livewire re-renders need nothing re-attached.
- All commands run in Docker. Before each commit: `docker compose exec app ./vendor/bin/pint --dirty --format agent`; before the last also `docker compose exec app ./vendor/bin/phpstan analyse`.
- Commit messages end with `(TOR-17)` and the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Each tile image opens the slideshow at its own index with all photos (test in Task 1).
2. A tile with one photo has no gallery class and looks as before.
3. Pagination and search keep hover and dots working (manual, Task 2).

---

### Task 1: Up to three photos per tile

**Files:**
- Modify: `app/Filament/Schemas/ItemGrid.php`
- Test: `tests/Feature/Panel/PhotoSlideshowTest.php`

- [ ] Write failing tests: an item tile with five photos renders three images, indexes `[0, 1, 2]`, each with all five slideshow URLs; images 2 and 3 carry `loading="lazy"`, the first does not; the tile carries the class `tory-gallery` only with two or more photos. Restrict the existing "a list thumbnail opens all photos … from the first" test to the imports list.
- [ ] `->limit(3)`, `loading="lazy"` for every image but the first, `tory-gallery` on the column when the record has two or more existing photos.
- [ ] Run `docker compose exec app php artisan test --compact tests/Feature/Panel/PhotoSlideshowTest.php tests/Feature/Panel/DashboardTest.php`, Pint, commit.

### Task 2: Strip, hover and dots

**Files:**
- Create: `resources/js/item-gallery.js`
- Modify: `resources/css/item-grid.css`, `app/Providers/Filament/AdminPanelProvider.php` (`@vite` list)

- [ ] CSS: `.tory-gallery` is a horizontal scroll-snap strip, one photo wide, no scrollbar; dots on the column wrapper so they do not scroll; active dot from `data-active`.
- [ ] JS: on `pointermove` with a mouse/pen over `.tory-gallery`, scroll instantly to the strip under the pointer; on `pointerleave` back to the first; on `scroll` (capture) set `data-active` from `scrollLeft`.
- [ ] Check by hand with playwright-cli on localhost: hover strips, leaving the tile, dots, click opening the slideshow at the visible photo, after paging; touch emulation for swiping. A real phone is left to the user.
- [ ] PHPStan, Pint, commit.
