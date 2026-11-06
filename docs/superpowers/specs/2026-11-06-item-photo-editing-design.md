# Add and delete item photos — design

Date: 2026-11-06. Status: approved. Ticket: TOR-3. Builds on TOR-2. Vocabulary: `CONTEXT.md`.

## Intent

The user forgot to upload a picture on "Erfassen" and wants to add it later without redoing the item. Today an item's photos can only be reordered after "Übernehmen". Adding is the main case; deleting comes with it.

## Goals

The edit page of an item gets one field, "Fotos", which replaces the TOR-2 field "Foto für die Übersicht" and sits in the same place, first in the form. It shows each photo once and is where photos are added, removed and chosen for the overview.

### Behaviour of the field

- The photo at the front of `photos` carries the overview mark (ring and check mark). The others are faded, as in TOR-2.
- A click or tap on a photo makes it the overview photo. A photo added in the same edit can be chosen too.
- A ✕ on a photo marks it for deletion: it turns grey and shows "rückgängig", which undoes the mark. A marked photo cannot be chosen for the overview.
- Marking the overview photo moves the mark to the next photo that remains.
- A "+ Foto hinzufügen" tile comes after the photos. It disappears once the item would have 6 photos, counting added photos and not counting marked ones.
- New photos appear at the end, in the order selected.
- The field also shows on an item with one photo, because that is where more get added.

### Adding

- The same rules as "Erfassen": JPEG, PNG, WebP or GIF, at most 12 MB per file, shrunk to fit within 2048 × 2048 px and never enlarged.
- The tile accepts several files at once. On a phone it opens the usual photo or camera picker.
- If a selection would go over 6 photos, none of that selection is added and a German message says why.
- A new photo shows a spinner while it uploads. A rejected file shows its reason on its tile, in German, and can be dismissed. It never counts as a photo.

### Saving

- Nothing is final until "Speichern". "Abbrechen", or leaving the page, keeps the item as it was.
- After saving, an item has at least 1 and at most 6 photos. The only photo can be deleted if a new one is added in the same save.
- Saving with no photo left shows "Mindestens ein Foto" and changes nothing. Going over 6 shows a matching German message and changes nothing.
- Deleted files are gone for good; there is no trash.
- The new order is: the chosen overview photo, then the remaining existing photos in their order, then the new photos in the order they were added. Without a choice, the first remaining photo stays first.

### Texts and search

- Title, description, search terms and the embedding do not change when photos are added or deleted, and no embedding job is dispatched.
- "Neu generieren" stays a manual action and uses the photos as saved. The edit page does not ask about or suggest it.

## Non-goals

- Adding or deleting photos on the view page or on imports, or in "Übernehmen"
- Free reordering
- A trash or restoring deleted photos
- Regenerating automatically
- Enlarging a photo from this field; a click only chooses it

## Anti-goals

These would be failures even if the feature appeared to work:

- A photo that is still in use loses its file.
- A file is deleted when the save did not go through.
- A second open tab brings back a deleted photo or leaves `photos` pointing at a missing file.
- Adding a photo changes the overview photo or recomputes an embedding.

## Constraints

- **User's decision:** one combined field (sketch B) instead of Filament's upload field beside the TOR-2 field, accepting that it is custom-built and carries more risk.
- No new dependency, and no change to `.env` or the Docker image.
- UI copy is German. The field works by keyboard and on phones, in light and dark mode.
- Filament's precompiled theme has no uncompiled Tailwind classes, so the styling stays plain CSS. `resources/css/photo-picker.css` already exists and can be replaced.
- Photos stay on the private `local` disk under `imports/`, shown through temporary signed links, as everywhere else.
- A photo file belongs to exactly one import or item (project rule).
- The existing guard stays: a page opened before "Neu generieren" started must not save during the run (`EditItem::beforeSave()`).
- Every failure path keeps the item as it was. Filament wraps `afterSave` in the save transaction, so it runs **before** the commit; file deletion must wait for the commit (for example `DB::afterCommit`).

## How it works

These are the builder's starting points; the first three are my calls on safety, and the user has read them.

- **Apply changes, don't overwrite (my call).** A save sends what changed: photos to remove, uploads to add, and the chosen overview photo. It locks the row, reads the current `photos`, and applies those changes to them. The 1-to-6 limit is checked against that result. A chosen overview photo that no longer exists falls back to the first remaining photo.
- **Delete after commit (my call).** Only files that were on the row and were removed are deleted, and only after the commit. A failed file deletion is reported with `report()` and does not undo the save: a leftover file is acceptable, a lost photo is not.
- **Resize in the browser (my call).** The tile's script shrinks images on a canvas to the Erfassen limits before uploading through Livewire's own upload. The server still checks type and size on every file, because the browser cannot be trusted. Photos within the limits are uploaded unchanged, and animated GIFs are not resized, so they stay animated. The camera's orientation must survive.
- **Uploads** stay Livewire temporary files until the save, which moves them to `imports/` under generated names. If the save fails, files already moved are removed if possible. Abandoned temporary files are cleaned up by Livewire's existing rule.

## Calls I made

- An old item that has no photos can still be edited and saved without adding one, because a text correction should not be blocked by a limit it could not have met. The existing test `an item without photos can be edited` stays valid. Once any photo is added, the normal limits apply.
- A rejected file does not block saving once dismissed. While a photo is still uploading, "Speichern" waits or is disabled.
- A file over 12 MB is rejected even if shrinking would have brought it under the limit, matching "Erfassen" as far as the builder can confirm when building.

## Testing

Follow `testing-best-practices`: feature tests through the edit page with `Storage::fake('local')`. They must cover:

- Adding a photo appends it and keeps the overview photo.
- Choosing a just-added photo puts it first.
- Marking the overview photo moves the next remaining one forward.
- Replacing the only photo in one save works.
- 0 photos and 7 photos are rejected, changing neither the row nor any file.
- The server rejects a wrong file type and a file over 12 MB.
- Removed files are deleted after a save; a failed save deletes nothing.
- A second tab's stale save cannot bring back a deleted photo.
- A forged path that is not on the item is neither deleted nor added.
- Adding or deleting dispatches no `EmbedItem` and keeps the embedding.
- The TOR-2 tests are adapted where the field's name or state changes, without weakening what they check.

The browser part (canvas, spinner, marks) is not reachable from the test suite. It needs a manual check on a real phone and a desktop browser, against production-sized images.

## Left to the builder

- The shape of the field's state, and whether it is a Filament custom field or a Livewire piece embedded in the form.
- Exact German messages beyond those above, and the JPEG quality for resized photos.
- Whether image metadata survives resizing. It does not matter here unless orientation breaks.
- Adding the behaviour to the Items section of `CLAUDE.md`, replacing the TOR-2 sentence.
