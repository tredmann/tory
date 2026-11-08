# Item notes — design

Date: 2026-11-08. Status: draft for approval. Ticket: TOR-15.

## Intent

An item needs a place for what the photos and the AI texts cannot say: "ohne Netzteil", "Dongle fehlt", "Fernbedienung liegt in der Schublade". Today an item has title, description and search terms, and all three may be written by the AI: "Neu generieren" replaces them. A remark the user typed into the description would be lost on the next regeneration. The note is therefore a field of its own that only the user writes.

## Goals

- Every item has an optional free-text note ("Bemerkung").
- The note is readable everywhere an item is: panel, REST API, MCP.
- The note is writable in the panel and in the REST API, both when creating (`POST /api/v1/items`) and when changing (`PATCH /api/v1/items/{item}`) an item.

## Non-goals

- Search: neither the keyword search nor the embedding uses the note. Changing the note does not re-embed the item. Can be added later.
- Imports and bulk imports: no note field there; "Übernehmen" (panel and `POST /api/v1/imports/{import}/convert`) stays unchanged. The note is added on the item afterwards.
- An MCP tool that writes the note. The MCP server has no tool that creates or edits items today; only `find-items` returns it.
- Showing the note on the item grid tiles.

## Anti-goals

- The AI writes, changes or clears the note. `RegenerateTexts` and the extractor never touch it.
- Changing the note costs an embedding call.

## Decisions

- **Storage:** a nullable `text` column `notes` on `items`, no default, fillable. No length limit, like `description`. Existing items get `null`.
- **Glossary:** `CONTEXT.md` gets the term **Note** (Bemerkung) under "Things", in its existing format.
- **Panel edit page:** a `Textarea` "Bemerkung" after "Beschreibung" in `ItemForm`. The existing rule that an edit page does not save while a regeneration runs applies to the note as well (no exception for it).
- **Panel view page:** a `TextEntry` "Bemerkung" after "Beschreibung" in `ItemInfolist`, placeholder "—", line breaks kept (`white-space: pre-line`), like the description.
- **Embedding:** `notes` stays out of the dirty lists in `Item::booted()` and out of `ItemEmbedder::textFor()`.
- **REST API:** `StoreItemRequest` takes `notes` as `nullable|string`, `UpdateItemRequest` as `sometimes|nullable|string`, each with a short PHPDoc comment and `@example` so Scramble documents it. An empty string arrives as `null` (Laravel's global `ConvertEmptyStringsToNull`), so sending `""` or `null` clears the note. `ItemResource` returns `notes` (string or `null`) after `description`. `docs/api-handover.md` names the field in its item description and examples.
- **MCP `find-items`:** each item in the structured content gets `notes` (`null` when empty), shortened to 200 characters like the description. The text line gets ` – Bemerkung: …` appended when a note exists, because clients often read only the text; there the note's line breaks become spaces so each item stays on one line. *(Builder's call, recorded here.)*

## Left to the builder

- Test layout, following the existing tests in `tests/Feature/Panel/ItemResourceTest.php`, `tests/Feature/Api/ItemsTest.php`, `tests/Feature/Mcp/FindItemsToolTest.php` and `tests/Feature/Jobs/RegenerateTextsTest.php` (regeneration keeps the note).
- Whether the factory sets `notes` (default `null` is fine).
