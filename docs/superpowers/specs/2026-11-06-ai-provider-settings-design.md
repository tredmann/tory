# AI provider settings — design

Date: 2026-11-06. Status: draft for approval. Builds on `2026-11-05-async-import-design.md`.

## Intent

The provider and model behind object recognition (`InventoryExtractor`) are set in `.env` today. The user does not want to "wurschteln" in server settings every time they change something. They want a settings page in the spirit of "Bring your own AI": create several providers with their credentials, pick the model right there, and switch to whichever one they want to use at the moment. Locally that is Ollama; in real use it may be Claude, OpenAI or Mistral over their APIs.

## Goals

- A settings page in the panel lists the configured providers as cards. Exactly one can be active, and it stands out at a glance.
- Create, edit and delete providers there, including API keys and the Ollama address, without server access or a restart.
- Choose the model per provider from a list fetched from the vendor, or type a name by hand.
- Activate another provider with one click; the next import uses it.
- Test a provider from its card: does this key and model answer a photo with a usable result?
- The same vendor can be set up more than once under different names, e.g. "Claude schnell" and "Claude gründlich" with different models.

## Non-goals

- **Embeddings and search.** `ItemEmbedder` keeps its own `ai.inventory.embedding_*` configuration. Another embedding model would mean recomputing every item.
- Vendors other than Ollama, Anthropic (Claude), OpenAI and Mistral in the UI. Adding one later should be a small change, but none is exposed now.
- Automatic failover between providers.
- Per-user providers, roles or permissions. Tory stays a single household: every verified user may manage providers and switch the active one.
- Changing the registration setting (see "Open before public deployment").
- Other settings on this page, and any generic settings framework.
- Choosing a provider per import.

## Anti-goals

- **Photos reaching a cloud vendor without the user choosing that.** Nothing ever switches the active provider on its own. If the active provider fails, the import fails.
- **Keys readable in plain text** anywhere after saving: UI, logs, import errors, notifications or exception reports. One deliberate exception, added later at the user's request: the provider export file, behind a warning, so credentials can move between installations.
- **Existing installations breaking on upgrade.** No setup may be required for imports to keep working.
- **A test gate.** Activating never requires a successful test, so the user is never blocked.

## Behaviour

### Page

Layout is variant B from the mockups: a page titled "Eigene KI" with a "Provider anlegen" button and one card per provider.

- The active card has a highlighted border and an "Aktiv" badge. Inactive cards have an "Aktivieren" button.
- Each card shows the name, the model, and either the address (Ollama) or the shortened key (`••••3fa9`).
- Each card has "Bearbeiten", "Testen" and "Löschen". Delete asks for confirmation.
- Creating and editing happen in a dialog.
- With no active provider, the page says that the server configuration (`.env`) currently applies.

All UI copy is German. "Eigene KI" is the builder-side working title chosen during brainstorming; it can be renamed in one place.

### Provider fields

| Field | Rules |
|---|---|
| Name | Required, unique, free text, shown on the card |
| Anbieter | Required: Ollama, Claude, OpenAI, Mistral. Fixed after creation, because a key belongs to one vendor |
| API-Key | Required for Claude, OpenAI, Mistral. Not shown for Ollama |
| Adresse | Ollama only, required, default `http://host.docker.internal:11434` |
| Modell | Required |

Cloud vendors use their standard API addresses, as in `config/ai.php`; the address is not editable for them.

### API keys

Stored encrypted and never sent back to the browser. The edit form shows an empty field with the shortened key as a hint; leaving it empty keeps the stored key. Keys are encrypted with `APP_KEY`, so changing `APP_KEY` makes them unreadable. The page must keep rendering in that case: the card shows a warning such as "Key nicht lesbar, bitte neu eingeben", and imports and tests report the problem as described below. Showing `••••3fa9` therefore must not depend on decrypting the key at render time.

### Choosing a model

Once the address or key is filled in, Tory fetches the vendor's model list (Ollama `/api/tags`, the others `GET /v1/models`) and offers it as a searchable selection. `laravel/ai` has no model listing, so this is our own HTTP code. When editing with an empty key field, the fetch uses the stored key.

Free text is always possible, because the fetch may fail or a model may be missing. A failed fetch shows a short German hint and never blocks saving. The lists do not reliably say which models understand photos, so unsuitable models may appear; no filtering is attempted. The test exists to catch that.

### Activating

Exactly one provider is active at most, enforced by the database. Activating is immediate and applies from the next extraction started:

- An extraction already running stays on the provider it started with.
- A pending import and a retry use the provider active when that attempt starts.

The queue worker is long-lived, so the active provider and its credentials must be read fresh for every job, never cached in the process.

A newly created provider is not activated automatically, even the first one, so the user can test it first.

### Using the active provider

`InventoryExtractor` runs on the active provider's vendor, credentials and model. `AiManager::build()` in `laravel/ai` creates a provider from a config array at runtime, which is the expected route. With no active provider, the extractor behaves exactly as today, using `ai.inventory.*` from `.env`.

The import becomes "Fehlgeschlagen" and offers Retry, as today. What the import page says depends on the failure:

- **Fixable on the settings page** (key rejected or expired, stored key unreadable, unknown model, model cannot handle images, provider unreachable): a fixed German sentence naming the provider and the reason, with a link to "Eigene KI", e.g. "Claude hat den API-Key abgelehnt. Provider prüfen". This shows with `app.debug` off and is the one deliberate change to today's import page.
- **Everything else:** the existing general message.

The raw error is still shown only when `app.debug` is on and must not contain a key. Imports and the test action share one failure classification, so they give the same reason for the same problem. A failure that cannot be recognised reliably falls into "everything else".

### Testing

"Testen" uses the provider's saved values, even if it is inactive. It sends a small bundled test image through the same structured-output path as a real extraction (`title` and `description`), and reports success or a short German reason as a notification. A wrong key, an unknown model and a model that cannot handle images should be told apart as far as the vendor's error allows. The test cannot prove a model really sees the photo: a text-only model that ignores the image and still answers in the expected format passes.

The test runs in the page request with a timeout, and its result is not stored. A local Ollama model may need a long time to load, so the timeout must allow for that and the button must show that it is working. On cloud vendors a test costs a fraction of a cent.

### Deleting

The active provider cannot be deleted; its delete action is hidden or disabled with a hint to activate another one first. This means the last active provider cannot be removed, and the UI offers no way back to the `.env` fallback once one has been activated. Inactive providers can be deleted at any time.

### Navigation

A separate navigation entry below Gegenstände, Lagerorte and Importe.

## Constraints

- Follow the job timing invariant in `CLAUDE.md`. `ExtractImport` keeps `$tries = 1` and `$timeout = 200`.
- Existing test tooling keeps working: `InventoryExtractor::fake(...)` must still intercept extraction when a provider is active.
- No automated test talks to a real vendor. Model listing is tested with `Http::fake()`.
- No new Composer or npm dependencies.
- Provider rows follow the app's numeric key handling (`ResolvesNumericRecordKeys`) if they get their own URLs.

## Testing focus

- Extraction uses the active provider's vendor, key and model, and read fresh per job; it falls back to `.env` with no active provider.
- Activating leaves exactly one active. Concurrent activation cannot produce two.
- The active provider cannot be deleted.
- Keys are encrypted at rest, never present in the rendered page or Livewire state, and kept when the field is left empty.
- A failed model list fetch does not block saving; free-text models save.
- Test action: success and failure reporting.
- Failures do not switch providers.
- The import page shows the provider-specific sentence and link for fixable failures with `app.debug` off, and the general message otherwise.
- An unreadable stored key does not break the page.

## Open before public deployment

Self-registration is switched on in `AdminPanelProvider`. On a publicly reachable install, anyone who registers and verifies an email address could download the stored keys in plain text through the export, spend them and change the active provider. Not a problem while Tory runs locally, and out of scope here. It must be decided before going public, for example by turning registration off.

## Left to the builder

- Custom Filament page or a resource with a custom list view, as long as it behaves as described.
- Table and model naming, migration details, how "at most one active" is enforced.
- Where the provider-resolving code lives, how the extractor consumes it, and the test image.
- The exact widget for "select or type freely", when the list fetch fires, and whether fetched lists are cached.
- The test timeout and how it stays clear of PHP and server request limits.
- Keeping `README.md`, `CLAUDE.md` and `.env.example` accurate, including that `.env` is now only the fallback for extraction.
