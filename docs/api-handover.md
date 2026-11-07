# Tory REST API v1 — handover for the mobile app

This document is for whoever builds the mobile client for Tory. It explains what the app can do through the API, what every endpoint expects and returns, and how the screens of a typical app map onto calls. The machine-readable OpenAPI description lives at `/docs/api` (UI) and `/docs/api.json` on every installation; you need to be logged in to the web panel with a verified account to open it. When this document and the OpenAPI output disagree, the code wins — tell the backend side.

## 1. What Tory is, in five terms

| Term | Meaning |
|---|---|
| **Item** (`Gegenstand`) | A thing the household owns: title, description, search terms, 0–20 photos, and the spot where it lies. |
| **Spot** (`Lagerplatz`) | A concrete place: a box, a shelf, a drawer. Identified by a 4-character **code** such as `K7XM`, printed as a QR label. |
| **Location** (`Lagerort`) | A group of spots, e.g. "Keller" or "Dachboden". Has a UUID and a unique name. |
| **Import** | A photo upload waiting to become an item. A worker sends the photos to an AI model, which suggests title, description and search terms. The user then reviews and **converts** it into an item. |
| **Device token** | What the app gets at login. One per device, never expires, revoked by logout. |

The main loop of the app: **photograph a thing → upload as import → wait for the suggestion → review → convert onto a spot**. The second loop: **find a thing** (search, or scan a spot's QR label to see what is inside).

All user-facing texts the API returns (validation errors, status labels, conflict messages) are **German**. Show them as they come.

## 2. Basics

### Base URL

`https://<installation>/api/v1` — e.g. `https://tory.example.com/api/v1`. Everything below is relative to that.

### Headers

| Header | Value |
|---|---|
| `Authorization` | `Bearer <token>` on every request except `POST /login` |
| `Accept` | `application/json` recommended; the server forces JSON anyway, so image loaders sending `Accept: image/*` still get JSON errors |
| `Content-Type` | `application/json` for JSON bodies, `multipart/form-data` for the photo upload |

### Response envelope

- A single object comes wrapped: `{"data": { … }}`.
- A full list (spots, locations) comes as `{"data": [ … ]}`.
- A paginated list (imports, items) comes as Laravel's standard shape, 24 per page:

```json
{
  "data": [ … ],
  "links": { "first": "…?page=1", "last": "…?page=3", "prev": null, "next": "…?page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 3, "per_page": 24, "to": 24, "total": 61, "path": "…", "links": [ … ] }
}
```

Use `links.next` to load more. It keeps your filter and search parameters.

- `POST /login` is the only exception: it returns `{"token": "…"}` without `data`.
- `204 No Content` responses have no body.
- Timestamps are ISO 8601 in UTC, e.g. `"2026-11-07T09:32:33.000000Z"`.

### Identifiers

- Imports, items and locations: UUIDs (strings). Anything that is not a UUID answers `404`.
- Spots: **always their code**, never a number. The API accepts the code in any case (`k7xm` = `K7XM`) and always returns it uppercase. Codes use only `23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (no `0`, `O`, `1`, `I`), so you can validate a scanned value locally before calling.

### Errors

| Status | When | Body |
|---|---|---|
| `401` | Missing, wrong or revoked token | `{"message": "Unauthenticated."}` → drop the token, show login |
| `403` | Account email not verified | `{"message": "Bitte bestätige zuerst deine E-Mail-Adresse."}` |
| `404` | Unknown id/code, photo not on that record, import already converted | `{"message": "…"}` — don't rely on the text |
| `409` | The action is not allowed in the record's current state (see each endpoint) | `{"message": "<German sentence>"}` → show it, refresh the record |
| `422` | Validation failed | `{"message": "…", "errors": {"field": ["German message", …]}}` → show per field |
| `429` | Too many login attempts (5 per minute per IP) | wait for `Retry-After` seconds |
| `5xx` | Server trouble | generic retry message |

Validation keys for array fields are indexed, e.g. `photos.2` or `search_terms.0`.

## 3. Objects

### Spot

```json
{
  "code": "K7XM",
  "name": "Werkzeugkiste blau",
  "note": "unten links im Regal",
  "label": "K7XM · Werkzeugkiste blau",
  "location": { "id": "0193…", "name": "Keller" },
  "items_count": 12
}
```

`label` is what Tory shows everywhere (`{code} · {name}`). `note` may be null. `items_count` is only present on `GET /spots`, `GET /spots/{code}` and `POST /spots`; inside an item or import it is absent.

### Location

```json
{ "id": "0193…", "name": "Keller", "spots_count": 7 }
```

`spots_count` only on `GET /locations`.

### Item

```json
{
  "id": "0193…",
  "title": "Akku-Bohrschrauber",
  "description": "Grüner Akku-Bohrschrauber mit zwei Akkus und Ladegerät im Koffer.",
  "search_terms": ["Bohrmaschine", "Akkuschrauber", "Werkzeug"],
  "spot": { "code": "K7XM", "name": "…", "note": null, "label": "…", "location": { "id": "…", "name": "Keller" } },
  "photos": [
    "https://tory.example.com/api/v1/items/0193…/photos/aB3x…jpg",
    "https://tory.example.com/api/v1/items/0193…/photos/Zk9q…png"
  ],
  "thumbnails": [
    "https://tory.example.com/api/v1/items/0193…/photos/aB3x…jpg/thumb",
    "https://tory.example.com/api/v1/items/0193…/photos/Zk9q…png/thumb"
  ],
  "regenerating": false,
  "created_at": "2026-11-07T09:32:33.000000Z",
  "updated_at": "2026-11-07T10:01:12.000000Z"
}
```

- `description` may be null. `search_terms` is always a list, possibly empty.
- `photos` is ordered; the **first is the overview photo** (use it for tiles). It may be empty for old items — show a placeholder.
- `thumbnails` matches `photos` one to one, 400 px WebP; use it for tiles and lists.
- `regenerating: true` means someone pressed "Neu generieren" in the web panel and new texts are being written. Show the item read-only and offer no edit until it is false again (an edit would answer `409`).

### Import

```json
{
  "id": "0193…",
  "status": "completed",
  "status_label": "Fertig",
  "title": "Akku-Bohrschrauber",
  "description": "…",
  "search_terms": ["Bohrmaschine", "Werkzeug"],
  "spot": { "code": "K7XM", … },
  "photos": ["https://…/api/v1/imports/0193…/photos/aB3x…jpg"],
  "thumbnails": ["https://…/api/v1/imports/0193…/photos/aB3x…jpg/thumb"],
  "failure_message": null,
  "error": null,
  "regenerating": false,
  "created_at": "2026-11-07T09:30:02.000000Z"
}
```

| `status` | `status_label` | Meaning | What the app offers |
|---|---|---|---|
| `pending` | Wartet | queued | spinner, poll |
| `running` | Läuft | the AI is working (usually seconds, at most a few minutes) | spinner, poll |
| `completed` | Fertig | title/description/search terms are filled | review form → convert; delete |
| `failed` | Fehlgeschlagen | extraction failed | show `failure_message`; retry; convert by hand; delete |

- `title`, `description` are null until `completed`. `search_terms` is `[]` until then.
- `failure_message` is a German sentence when `failed`, otherwise null. Some failures name a problem only the household admin can fix in the web panel ("Eigene KI"), e.g. "Claude hat den API-Key abgelehnt." — show it as is.
- `error` is the raw technical error and is **only filled on installations running in debug mode**. Production returns null. Don't build UI on it.
- `spot` may be null only for very old imports; imports created through the API always have one.

### User

```json
{ "id": "0193…", "name": "Anna", "email": "anna@example.com", "last_spot": "K7XM" }
```

`last_spot` is the code of the spot the user picked last (on upload or convert), or null. Preselect it in spot pickers, as the web panel does.

## 4. Endpoints

### Authentication

#### `POST /login`

No token needed. Rate limit: 5 per minute per IP.

```json
{ "email": "anna@example.com", "password": "secret", "device_name": "Annas iPhone" }
```

| Result | Status | Body |
|---|---|---|
| success | `201` | `{"token": "17|Qm9…"}` |
| wrong email or password | `422` | `errors.email: ["Diese Zugangsdaten stimmen nicht mit unseren Daten überein."]` |
| account not verified | `403` | `message: "Bitte bestätige zuerst deine E-Mail-Adresse."` |
| missing fields | `422` | per field |

- `device_name` (max 255) names the token. Use something the user recognises, e.g. the device's name.
- Store the token in the platform's secure storage (Keychain / Keystore). It never expires.
- There is no registration, password reset or email verification in the API. Accounts are created and verified on the web; production installations may have self-registration switched off.

#### `POST /logout`

Revokes the token used for this request (other devices stay logged in). `204`. Delete the stored token afterwards either way.

#### `GET /user`

The logged-in user (see User). Call it after login and on app start to check the token is still valid (`401` → login screen).

### Locations

#### `GET /locations`

All locations, by name, with `spots_count`. Not paginated.

#### `POST /locations`

```json
{ "name": "Garage" }
```

`201` with the location. `422` if the name is missing, longer than 255, or taken ("Dieser Wert für Name ist bereits vergeben.").

### Spots

#### `GET /spots`

All spots, ordered by location name, spot name, code, each with `items_count`. Not paginated — a household has dozens to a few hundred. Use it for the spot picker; group by `location.name` as the web panel does, and search locally over code, name and location name.

#### `GET /spots/{code}`

One spot with `items_count`. The target of a scanned QR label (see Flow 4). `404` for an unknown or malformed code.

#### `POST /spots`

```json
{ "name": "Kiste 3", "note": null, "location": "<location uuid>" }
```

`201` with the new spot, including its generated `code`. `name` required (max 255), `note` optional, `location` must be an existing location id. A spot's code and its QR address never change; there is no API to edit or delete spots (that is done on the web).

### Imports

#### `POST /imports` — upload

`multipart/form-data`:

| Field | Value |
|---|---|
| `photos[]` | 1 to 20 files, JPEG, PNG, WebP or GIF, each at most 12 MB and at most 70 MB together; repeat the field once per file, in the order you want them |
| `spot` | spot code (any case) where the thing will go |

`201` with the import in status `pending`. The order of `photos[]` is kept; the first becomes the overview photo. The user's `last_spot` becomes this spot.

`422` cases: no photos, more than 20, wrong type, over 12 MB, over 70 MB together ("Alle Fotos zusammen dürfen höchstens 70 MB groß sein.", so shrink photos before uploading), unknown spot.

**Shrink before uploading.** The server stores what it gets and does not resize. The web panel shrinks every photo to fit 2048 × 2048 px (never enlarging, GIFs untouched); please do the same — it saves upload time, storage and AI cost, and keeps the whole request comfortably below the server's request size limit (80 MB per request, of which the photos may use 70 MB together).

#### `GET /imports`

All imports, newest first, paginated (24). Every import in this list is "open": converting deletes it. Use it for an inbox / "Offene Importe" badge.

#### `GET /imports/{id}`

One import. Poll it while `status` is `pending` or `running`; the web panel polls every 5 seconds. Stop polling when it is `completed` or `failed`, and when the screen is closed.

#### `POST /imports/{id}/retry`

Only for `failed` imports. Clears the old result and failure, sets `pending` and starts the AI again. `200` with the import. Otherwise `409` "Nur fehlgeschlagene Importe lassen sich erneut starten."

#### `POST /imports/{id}/convert` — "Übernehmen"

```json
{
  "title": "Akku-Bohrschrauber",
  "description": "Grüner Akku-Bohrschrauber …",
  "search_terms": ["Bohrmaschine", "Werkzeug"],
  "spot": "K7XM"
}
```

- `title` required (max 255). `description` optional (null allowed). `search_terms` optional list of strings (each max 255; empty strings are rejected, drop them client-side). `spot` required.
- Prefill the form from the import's `title`, `description`, `search_terms` and `spot` (fall back to the user's `last_spot`). The user may change everything.
- Works on `completed` **and** `failed` imports — after a failure the user can type the texts by hand.
- `201` with the new **item**. The import is gone afterwards; its photos now belong to the item (new photo URLs under `/items/…`). The user's `last_spot` becomes this spot.
- `409` "Der Import lässt sich gerade nicht übernehmen." while `pending`/`running` or while it is regenerating.
- `404` if the import no longer exists — e.g. converted a second time (double tap, second device). Nothing is created twice. Treat 404 here as "already done", refresh the inbox.

#### `DELETE /imports/{id}`

Deletes the import and its photos for good. Only when `completed` or `failed`; otherwise `409` "Der Import läuft noch." `204`.

### Items

#### `GET /items`

Paginated (24). Query parameters, all optional and combinable:

| Parameter | Effect |
|---|---|
| `search` | Free text. Items containing **every word** in title, description or a search term come first, then items that match **by meaning** (e.g. "Bohrmaschine" finds "Akkuschrauber"). Without it: newest first. |
| `location` | Location UUID: only items on spots of that location |
| `spot` | Spot code (any case): only items on that spot |

- An empty parameter (`?spot=`) means "no filter". An unknown location or spot gives an empty list, not an error.
- Semantic matching depends on an embedding service on the server. If it is down, search silently falls back to word matching only; the response looks the same.
- Example: `GET /items?search=akku&location=0193…&page=2`.

#### `GET /items/{id}`

One item.

#### `PATCH /items/{id}`

Partial update; send only what changed:

```json
{ "spot": "B4QR" }
```

| Field | Rule |
|---|---|
| `title` | if sent: required, string, max 255 |
| `description` | string or null |
| `search_terms` | list of strings, each max 255; **replaces** the whole list |
| `spot` | spot code (moves the item) |

`200` with the updated item. `409` "Wird gerade neu generiert. Deine Änderungen wurden nicht gespeichert." while the item is regenerating. `PUT` is not supported (`405`).

Photos of an item cannot be changed through the API (the web panel can).

#### `DELETE /items/{id}`

Deletes the item and its photo files for good. `204`. Ask the user to confirm first.

### Photos

#### `GET /imports/{id}/photos/{file}` and `GET /items/{id}/photos/{file}`

The URLs come ready-made in the `photos` arrays — never build them yourself. Each returns the image bytes with its content type (`image/jpeg`, `image/png`, `image/webp`, `image/gif`).

- **They need the bearer token** like any other call. Configure your image loader to send the `Authorization` header (e.g. a custom header provider / request interceptor in your image library).
- The URL of a photo stays the same as long as the photo belongs to that record, even when the order changes, so the URL is a good cache key. Cache to disk.
- After convert, an import's photo URLs answer `404`; the item has new URLs for the same files. After deleting a record, all its URLs answer `404`.
- A `401` here means the token is gone: go to login, as with any other call.

#### `GET /imports/{id}/photos/{file}/thumb` and `GET /items/{id}/photos/{file}/thumb`

The URLs come ready-made in the `thumbnails` arrays. Same rules as above; the body is a 400 px WebP. If a thumbnail cannot be made, the original is returned instead, with the original's content type (not WebP).

## 5. Flows

### Flow 1: Start and login

```
App start
 ├─ token stored? ── no ──► Login screen
 └─ yes ──► GET /user
             ├─ 200 ► remember last_spot ► Home
             ├─ 401 ► delete token ► Login screen
             └─ 403 ► "Bitte bestätige zuerst deine E-Mail-Adresse." ► Login screen

Login screen ──► POST /login {email, password, device_name}
             ├─ 201 ► store token ► GET /user ► Home
             ├─ 422 ► show errors.email under the form
             ├─ 403 ► show message
             └─ 429 ► "try again in Retry-After seconds"
```

### Flow 2: Capture a thing (the main loop)

```
Camera / gallery: 1–20 photos (70 MB together)
 ► shrink each to fit 2048 × 2048
 ► pick spot (preselect last_spot; list from GET /spots; optional "new spot" via POST /spots,
   which may need "new location" via POST /locations)
 ► POST /imports (multipart photos[] + spot)        ── 201 import (pending)
 ► poll GET /imports/{id} every ~5 s
     ├─ pending / running ► keep waiting (the user may leave; the import stays in the inbox)
     ├─ completed ► review form, prefilled ► POST /imports/{id}/convert ── 201 item ► item screen
     └─ failed    ► show failure_message
                     ├─ "Erneut versuchen" ► POST /imports/{id}/retry ► poll again
                     ├─ "Selbst ausfüllen" ► empty review form ► convert
                     └─ "Verwerfen" ► DELETE /imports/{id}
```

The user can photograph several things in a row without waiting: every upload is its own import, and the AI works through them in the background. An inbox (`GET /imports`) lets them review later.

### Flow 3: Find a thing

```
Search field ► GET /items?search=<text>   (debounce ~300 ms; optional location filter from GET /locations)
 ► tiles: photos[0], title, spot.code + "{location.name} · {spot.name}"
 ► tap ► GET /items/{id} ► detail: all photos, texts, where it lies
```

Show the spot prominently — "where is it" is the whole point of the app.

### Flow 4: Scan a spot label

The QR code on a label holds the web address `https://<installation>/spots/{code}`.

```
Scan ► take the last path segment as code ► check it against the code alphabet locally
 ► GET /spots/{code}                 ── 404 ► "Unbekannter Lagerplatz"
 ► GET /items?spot={code}            ── what is inside
 ► optional actions: capture into this spot (Flow 2 with spot preselected)
```

Consider registering the app for that URL (universal links / app links) so a scan with the system camera opens the app directly.

### Flow 5: Edit or move an item

```
Item screen ► regenerating? ── yes ► read-only, hint "wird neu generiert", refresh later
            └─ no ► edit form ► PATCH /items/{id} with changed fields only
                     ├─ 200 ► show updated item
                     ├─ 409 ► show message, reload item
                     └─ 422 ► show field errors
Move = PATCH /items/{id} {"spot": "<code>"}  (handy right after a scan)
```

Changing title, description or search terms makes the server recompute the search index for that item in the background; nothing to do on the client.

### Flow 6: Logout

`POST /logout` ► delete stored token and image cache ► login screen.

## 6. Things to know

- **Single household:** every logged-in user sees and may change every record. There are no per-user permissions to model.
- **Concurrency:** the web panel and other devices change the same data. Refresh lists on screen focus. Treat `404` on a record you just had as "deleted elsewhere" and `409` as "changed elsewhere, reload".
- **No offline writes in the API:** if you queue uploads offline, send them later as normal `POST /imports`; each succeeds or fails on its own.
- **Not in the API (web panel only):** AI provider settings ("Eigene KI"), label PDFs, "Neu generieren", changing an item's photos, editing or deleting spots and locations, registration and password reset.
- **Versioning:** breaking changes will come as `/api/v2`; `v1` stays as described. New fields may be added to objects at any time — ignore unknown fields.
- **Where things live in the backend** (for questions): routes `routes/api.php`, controllers `app/Http/Controllers/Api/V1/`, validation `app/Http/Requests/Api/V1/`, response shapes `app/Http/Resources/Api/V1/`, tests `tests/Feature/Api/`, design `docs/superpowers/specs/2026-11-07-rest-api-design.md`.
