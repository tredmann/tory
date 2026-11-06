# Photo slideshow — design

Date: 2026-11-06. Status: approved. Ticket: TOR-1. Vocabulary: `CONTEXT.md`.

## Intent

Photos are shown so small everywhere that hardly anything can be recognised. The user wants to tell from the list whether a row is the right item without opening its detail page, and to see a photo large when the thumbnail is not enough.

## Goals

- Larger thumbnails in all three lists: items, imports, and the items of a spot.
- Fully visible, non-overlapping thumbnails side by side on the view pages of items and imports. They wrap on narrow screens.
- A click on a thumbnail opens a slideshow of all photos of that item or import.
  - On a view page it starts at the clicked photo.
  - In a list only the first photo is visible, so it starts there.
- In a list, a click anywhere else in the row still opens the detail page. A click on the photo does not.

### Slideshow behaviour

- Browse with arrows, arrow keys, and swiping on phones.
- After the last photo comes the first, and the other way round.
- Close with an X button, Esc, or a click beside the photo.
- A position display such as "2 / 5".
- With a single photo: no arrows and no position display.

### Known exceptions on touch devices

The user accepted GLightbox 3.3.1's touch behaviour instead of custom touch code. These points come from reading its code and have not been tried on a device; the manual check confirms them.

- Swiping stops at the first and last photo. Arrows and arrow keys still wrap.
- Pinch-to-zoom stays, because it hangs on `touchNavigation`, the same setting as swiping.
- On phones, tapping beside the photo does not close the slideshow. The X does, and so does GLightbox's vertical swipe. iPads may behave differently.

## Non-goals

- Zoom on click. GLightbox enables it by default, so it must be switched off with `zoomable: false`. Pinch-to-zoom is a known exception (see above).
- Automatic advancing and a download button.
- More than one photo per row in the lists.
- Thumbnails or resized image variants. Thumbnails keep loading the original file.
- Slideshows anywhere else, such as the photo upload on the import form.
- Changing how photo links are generated or secured (see Constraints).

## Decided by the user

- GLightbox is the library (`glightbox` on npm, 3.3.1, MIT, no dependencies). It is the approved new dependency; nothing else may be added.
- The scope above, including lists, and the slideshow behaviour above.

## Constraints

- **Photo links stay as they are.** The photos are on the private `local` disk, and Filament's `getImageUrl()` gives temporary signed URLs. Anyone holding such a link can open it until it expires, logged in or not. The slideshow uses the same kind of link. A tab left open past the expiry shows broken photos until it is reloaded, which is accepted.
- **Livewire re-renders.** List pagination, filtering and search replace the rows, and the import view page polls every 5 s while pending or running. Clicks must keep working after a re-render, and a poll must not close an open slideshow.
- **No custom theme exists.** The panel uses Filament's precompiled theme, so Tailwind classes that Filament has not compiled are not available. Nothing loads `resources/js/app.js` or `resources/css/app.css` yet. The script and styles have to reach the panel in development and in the production image, which builds assets with `npm run build` and copies only `public/build` into the final stage.
- **YAGNI.** Use GLightbox's own behaviour wherever it has one. Build only what is left: starting it from a click, and the position display.

## Calls made by the author, open to change

- Thumbnail height: 80 px in lists, 160 px on view pages, width following the photo's proportions.
- Position display: GLightbox has none, so it is added with a few lines on its `slide_changed` event. This was checked in its README after the user approved the library. PhotoSwipe was the alternative, but needs image dimensions up front, which Tory does not store.

## Left to the builder

- How the photo URLs get from PHP to the slideshow, for example data attributes through `extraImgAttributes()`. Lists show only the first photo through `limit(1)` but need the URLs of all of them.
- How a click on a list photo is kept from following the row link (`recordUrl()`).
- Whether the shared configuration is a small helper or a repeated few lines. There are three lists and two view pages.
- How the assets are registered, for example with `FilamentAsset` or a render hook. Whichever is used must work in both environments.

## Testing

- Feature tests with Pest (`tests/Feature/Panel/`):
  - Every list and view page renders what the slideshow needs for all photos of a record.
  - A record with no photos renders without errors.
  - The view page thumbnails are no longer stacked, and the rows still link to the detail page.
- The test suite has no browser tests, so clicking, swiping and closing are checked by hand. This includes a real phone, the known exceptions above and the production build. The automation browser cannot load `localhost`, so a person has to do this check.

## Follow-ups when this ships

- Update TOR-1: lists are now in scope, and the note that photos are only accessible to logged-in users is wrong (see Constraints).
- Describe the new asset loading in the "Assets/styling" paragraph of `CLAUDE.md`, which says that nothing loads these entries yet.
