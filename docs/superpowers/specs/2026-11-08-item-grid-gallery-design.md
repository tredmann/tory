# Item grid gallery — design

Date: 2026-11-08. Status: approved. Ticket: TOR-17. Vocabulary: `CONTEXT.md`.

## Intent

An item tile in the item grid shows only its first photo. Often the first photo alone does not tell whether it is the right item: the second shows the label, the third the plug. The user wants to see a few more photos on the tile itself, without opening the item or the slideshow. The model is daisyUI's hover gallery (https://daisyui.com/components/hover-gallery/).

## Goals

- A tile of an item with two or more photos shows up to three of them. Everywhere `App\Filament\Schemas\ItemGrid` is used: items list, items of a spot, "Zuletzt erfasst" on the dashboard.
- **Pointer devices (`hover: hover`):** the photo is split into as many equal vertical strips as the tile shows photos. The pointer over strip k shows photo k. Leaving the tile returns to the first photo.
- **Touch devices (`hover: none`):** the photos can be swiped horizontally and snap one photo at a time.
- **Dots:** with two or more photos, dots at the bottom of the photo show how many there are and which is visible.
- **Click or tap** opens the slideshow at the visible photo. The slideshow still holds all photos of the item.
- Tiles with one photo or none look as before.

## Non-goals

- The imports list. It does not use `ItemGrid`.
- More than three photos on a tile, a "+n" hint for the rest, arrows on the tile.
- Animations between photos.

## Decided by the user

- Maximum three photos per tile.
- Dots on both kinds of device.
- Swiping on touch devices.

## Decisions

- **Markup:** `ItemGrid`'s `ImageColumn` goes from `->limit(1)` to `->limit(3)`, without Filament's "+n" text. Filament then renders up to three `<img>` side by side in `.fi-ta-image`, each with its own `data-slideshow-index`, so the slideshow already opens at the clicked photo with no change to `slideshow.js`.
- **One mechanism for both devices:** `.fi-ta-image` becomes a horizontal scroll container with scroll snap and no visible scrollbar, one photo wide. On touch devices that is the swipe. On pointer devices a few lines of JavaScript map the pointer's x position to a strip and scroll to that photo instantly; leaving the tile scrolls back to the first.
- **Deviation from the ticket:** the ticket says pure CSS and JavaScript only for the dots if needed. The hover itself also needs JavaScript: Filament renders only the `<img>` elements, so there is no element per strip for a CSS `:hover`, and one image cannot be both a narrow hover zone and fully visible. A custom view column would avoid that, but would replace `ImageColumn` and its placeholder handling for a gain the user does not see. The script is a new file `resources/js/item-gallery.js` in the existing `HEAD_END` `@vite` list, and the styles go into `resources/css/item-grid.css`.
- **Livewire re-renders:** pagination, search and filters replace the tiles. The script listens on `document` (pointer events delegated, `scroll` in the capture phase), like `slideshow.js`, so nothing has to be re-attached.
- **Dots:** drawn by CSS on the tile's photo, one per photo, the active one stronger. The script sets the active index as an attribute on the gallery when it scrolls.
- **Loading:** photos 2 and 3 get `loading="lazy"`.

## Left to the builder

- Exact look of the dots (size, colour, shadow over light photos) in light and dark mode.
- Where the dots' element or pseudo-element lives so that it does not scroll with the photos.

## Testing

- Feature tests in `tests/Feature/Panel/PhotoSlideshowTest.php` (which already covers the item lists): an item with five photos renders three images with indexes 0 to 2 and all five URLs for the slideshow; photos 2 and 3 are lazy; an item with one photo renders one image. The existing test that a list renders exactly one thumbnail stays for the imports list.
- Hover, swipe, dots and the slideshow start are checked by hand in the browser (playwright-cli for localhost) and on a real phone.
