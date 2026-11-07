# REST API v1 — design

Date: 2026-11-07. Status: draft. Ticket: TOR-8. Vocabulary: `CONTEXT.md`.

## Intent

A mobile app (built later, not part of this ticket) shall work as a client for Tory: log in, photograph things, follow the import, take it over onto a spot, find and edit items, and open a spot by scanning its label. Tory gets a JSON REST API under `/api/v1` for that. The API is a second door to the same rules as the panel, not a second implementation of them.

## Goals

### Authentication

- Laravel Sanctum API tokens (`laravel/sanctum`, installed with `php artisan install:api`). Tokens only; no cookie/SPA mode (`statefulApi` stays off).
- `POST /api/v1/login` with `email`, `password`, `device_name` (required, max 255). On success `201` with `{"token": "<plain text token>"}`; the token is named after `device_name`, so every device has its own.
- Wrong credentials: `422` with the German `auth.failed` message on `email`. An unverified user: `403` with "Bitte bestätige zuerst deine E-Mail-Adresse." and no token is created.
- Rate limit 5 requests per minute per IP on `login` (`429` beyond).
- `POST /api/v1/logout` deletes only the token used for the request: `204`.
- Tokens never expire. Every other endpoint requires `auth:sanctum` and `verified`; without a valid token `401`.
- `GET /api/v1/user` returns `{id, name, email, last_spot}` (`last_spot` is the spot code or null), so the app can preselect the last spot as the panel does.

### Identifiers

- Imports, items and locations are addressed by their UUID.
- Spots are addressed and referenced **only by their code**, never by the internal bigint id (`{spot}` in paths, `spot` in request bodies and responses). A code is accepted in any case; anything that cannot be a code answers `404` without a query.

### Endpoints

All under `/api/v1`, JSON in and out, except photo upload (multipart) and photo download (image).

| Method | Path | Does |
|---|---|---|
| POST | `/login` | token (see above) |
| POST | `/logout` | revoke current token |
| GET | `/user` | current user |
| GET | `/imports` | imports, newest first, paginated (24) |
| POST | `/imports` | upload `photos[]` (1–6) and `spot`; `201` |
| GET | `/imports/{import}` | one import |
| POST | `/imports/{import}/retry` | restart a failed import |
| POST | `/imports/{import}/convert` | "Übernehmen": `title`, `description`, `search_terms`, `spot`; `201` with the new item |
| DELETE | `/imports/{import}` | delete a finished import; `204` |
| GET | `/imports/{import}/photos/{photo}` | the photo file |
| GET | `/items` | items, paginated (24); `search`, `location` (UUID), `spot` (code) |
| GET | `/items/{item}` | one item |
| PATCH | `/items/{item}` | change any of `title`, `description`, `search_terms`, `spot` |
| DELETE | `/items/{item}` | delete item and its photo files; `204` |
| GET | `/items/{item}/photos/{photo}` | the photo file |
| GET | `/spots` | all spots, ordered by location name, spot name, code (not paginated) |
| POST | `/spots` | `name`, `note`, `location` (UUID); `201` |
| GET | `/spots/{spot}` | one spot with its item count |
| GET | `/locations` | all locations by name (not paginated) |
| POST | `/locations` | `name` (unique); `201` |

Spots and locations are returned whole because the app needs them as pick lists and a household has few of them. The items of a spot are `GET /items?spot={code}`.

### Behaviour, same as the panel

- **Upload:** the same file rules as "Erfassen": JPEG, PNG, WebP or GIF, at most 12 MB each, 1 to 6 files, `spot` required. Photos are stored on the private `local` disk under `imports/` in the order sent, the import starts as `pending`, the user's `last_spot_id` becomes the spot, and `ExtractImport` is dispatched. The server does not shrink photos; the app should (the panel shrinks in the browser too).
- **Retry:** only on a `failed` import, otherwise `409`. Resets exactly what the panel's "Erneut versuchen" resets and dispatches `ExtractImport`. Panel and API share one method for it.
- **Convert:** only on a `completed` or `failed` import that is not regenerating, otherwise `409`. Calls `Import::convertToItem()` and sets `last_spot_id`. A second convert of the same import answers `404` and creates nothing.
- **Delete import:** only when `completed` or `failed`, otherwise `409`.
- **Item list:** without `search` newest first; with `search` the order of `ItemSearch::apply()` (keyword matches, then semantic matches), falling back to newest first among equals. `location` and `spot` filters combine with `search`. An unknown `location` or `spot` gives an empty list, not an error.
- **Item update:** `409` while the item is regenerating (the finished job would overwrite the edit). Title required when sent, max 255; search terms a list of strings. Changing texts triggers re-embedding through the existing model events.
- **Spot create / location create:** same validation as the panel forms (location name unique, spot name required, max 255).

### Responses

- Eloquent API Resources. Lists of imports and items use Laravel's standard paginated shape (`data`, `links`, `meta`).
- **Import:** `id`, `status` (`pending|running|completed|failed`), `status_label` (German), `title`, `description`, `search_terms`, `spot` (spot object or null), `photos` (list of photo URLs in order), `failure_message` (the fixed German sentence for a classified failure, otherwise "Die Analyse ist fehlgeschlagen. Bitte erneut versuchen." when failed, as on the panel, otherwise null), `error` (raw error, only when `app.debug` is on, otherwise null), `regenerating` (bool), `created_at`.
- **Item:** `id`, `title`, `description`, `search_terms`, `spot` (spot object), `photos`, `regenerating`, `created_at`, `updated_at`. Never the embedding.
- **Spot:** `code`, `name`, `note`, `label` (`{code} · {name}`), `location` (`{id, name}`); `items_count` on `GET /spots` and `GET /spots/{spot}`.
- **Location:** `id`, `name`, `spots_count` on the list.
- Timestamps as ISO 8601.
- Errors as JSON (already configured for `api/*` in `bootstrap/app.php`), messages in German from `lang/de` or fixed German sentences for `409`.

### Photos

- A photo URL is `/api/v1/{imports|items}/{id}/photos/{file name}`, where the file name is the basename of the stored path (`imports/<random>.<ext>`). It needs the bearer token, stays valid as long as the photo belongs to that record, and does not change when the order of photos changes.
- A file name that is not among the record's photos answers `404`. After "Übernehmen" the photo is reachable only under the item.
- The response streams the file from the `local` disk with its content type.

### Documentation

- `dedoc/scramble` (approved) generates OpenAPI from routes, Form Requests and Resources at `/docs/api` (UI) and `/docs/api.json`. It documents bearer authentication.
- Reachable in every environment, but only for a user logged in to the panel (session) with a verified email; anyone else gets `403`. Scramble's `viewApiDocs` gate carries the rule; locally Scramble's own "always allow" in `local` stays as it is.

## Non-goals

- The mobile app
- Eigene KI (providers), label PDFs, "Neu generieren", changing an item's photos, creating items other than by convert
- Registration, password reset, email verification, profile, theme
- Token expiry, token management UI, scopes/abilities
- Server-side resizing of photos
- Spot edit/delete, location edit/delete

## Anti-goals

These would be failures even if the API appeared to work:

- A rule the panel enforces (photo limits, convert only once, no edit during regeneration, spot code immutable) can be bypassed through the API.
- A photo file is reachable without a token, or through a record it does not belong to.
- An unverified user gets a token.
- The internal spot id appears in a request or response.
- An API key of an AI provider or an embedding appears in any response.

## Constraints

- Laravel 13, Sanctum's `personal_access_tokens` must use `uuidMorphs('tokenable')` because users have UUID keys (the published migration uses `morphs`, i.e. bigint).
- No policy layer: every verified user sees every record (single household).
- Panel stays at `/`; `routes/web.php` still defines no `/`.
- New dependencies: `laravel/sanctum` (first-party, needed for the approved token login) and `dedoc/scramble` (approved).
- `CLAUDE.md` and `CONTEXT.md` describe the API afterwards.
