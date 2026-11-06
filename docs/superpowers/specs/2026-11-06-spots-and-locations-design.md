# Spots and locations — design

Date: 2026-11-06. Status: draft for approval. Builds on `2026-11-06-items-locations-search-design.md`. Vocabulary: `CONTEXT.md`.

## Intent

What the app calls a "Lagerort" today is really the concrete place an item lies in: a shelf, a box. The user needs a further level above it to combine such places, for example everything in the cellar. So today's concept becomes **Lagerplatz** (`Spot`), and the word **Lagerort** (`Location`) moves up to the new grouping level.

The purpose of the location is orientation: seeing "Keller → K7XM · Regal 3" instead of only "K7XM · Regal 3". It is deliberately not a second full-featured concept.

## Goals

- Rename today's concept to Lagerplatz, in the interface and completely in the code, so `StorageLocation` no longer exists.
- Introduce Lagerorte as a simple named grouping; every spot belongs to exactly one.
- Move spots between locations; their code stays and their items go with them.
- Show the location wherever a spot is shown, and filter the item list by location.
- Group the spot picker by location and search it by either name.
- Switch all URL slugs to short English words while no label exists yet.

## Non-goals

- More than two levels. Finer divisions go into the spot's name.
- A code, QR target, label or detail page for locations.
- Items directly in a location. "Somewhere in the cellar" is a spot such as "Keller, allgemein".
- Moving a whole location, per-location permissions, separate households.
- Finding items by the name of their location through the text search; the filter covers that. The search index and embeddings stay untouched.
- Redirects from old URLs. No label is in use, so at most bookmarks break.
- Uniqueness of spot names, and case-insensitive uniqueness of location names ("Keller" and "keller" may coexist).

## Model

| Term | Model | Table | Key |
|---|---|---|---|
| Lagerplatz | `Spot` | `spots` | internal bigint `id`, visible `code` (unchanged) |
| Lagerort | `Location` | `locations` | UUID |

- `locations`: `name` (unique, editable), timestamps.
- `spots.location_id`: NOT NULL foreign key, restrict on delete, editable.
- Renamed columns: `items.spot_id`, `imports.spot_id`, `users.last_spot_id`. Their foreign key behaviour stays as it is.
- A spot's `label` stays `{code} · {name}`. A new `full_label` gives `{location} → {code} · {name}`.

## Migration

- New migrations only; migrations that have already run stay untouched. Renaming keeps all rows, ids and codes, and also renames the primary key, unique constraint, foreign keys and sequence. Postgres' generated not-null constraint names keep their old names, as nothing refers to them.
- When spots exist, a location named "Allgemein" is created and all spots are assigned to it. On an installation without spots, no location is created.
- "Allgemein" is an ordinary location: it can be renamed, and deleted once empty.

## Interface (German)

Navigation order: Gegenstände, Lagerplätze, Lagerorte, Importe, Eigene KI.

**Lagerorte page** (`/locations`): a single page with a table of name and "Lagerplätze" (count), and create, edit and delete in modals; no detail page. Delete is disabled with the tooltip "Enthält noch Lagerplätze" while spots belong to the location; the foreign key is the backstop.

**Spot form:** a required field "Lagerort", editable later, with inline creation of a location. If exactly one location exists, it is preselected.

**Spot picker** ("Lagerplatz", on the import form, in "Übernehmen" and on the item edit page):

```
Keller
  K7XM · Regal 3
  P2QA · Kiste Weihnachten
Garage
  H9TR · Werkbank
```

- Groups sorted by location name, spots within them by name and then code.
- The search runs on the server, because Filament's client-side search does not match group names. Every whitespace-separated word must occur in the spot's code, the spot's name or the location's name, case-insensitively, with `%`, `_` and `\` treated literally.
- Preselecting the last spot and creating a spot inline work as before; the inline form now includes "Lagerort".

**Display:**

- Items and imports (list and view page) show `full_label` under the label "Lagerplatz"; for items it links to the spot's page.
- The spot list has a column "Lagerort", the spot's view page a field "Lagerort".
- The item list has a filter "Lagerort" that combines with the text search.

## URLs

| Page | Before | After |
|---|---|---|
| Lagerplätze | `/lagerorte`, `/lagerorte/{code}` | `/spots`, `/spots/{code}` |
| Lagerorte | none | `/locations` |
| Gegenstände | `/gegenstaende` | `/items` |
| Importe | `/importe` | `/imports` |
| Eigene KI | `/eigene-ki` | `/ai-providers` |

`/spots/{code}` is the future QR target. From now on, spot codes and this address must not change. Old URLs answer 404.

## Delivery

1. Mechanical rename to `Spot`, including `/spots`, with no change in behaviour.
2. English slugs for the other areas.
3. The `Location` concept, in several small commits.

## Testing

Pest feature tests as elsewhere in the project. The migrations are tested against real rows, not only against an empty schema. The picker's search needs one manual check in the browser, because tests do not run Filament's JavaScript.
