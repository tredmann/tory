# Selectable embedding provider — design

> The `.env` fallback was removed by TOR-49, see 2026-11-22-onboarding-design.md.

Date: 2026-11-06. Status: draft for approval. Builds on `2026-11-06-ai-provider-settings-design.md`, which listed embeddings as a non-goal.

## Intent

Embeddings run on a local Ollama model (`bge-m3`), chosen in `.env`. Production needs another service such as OpenAI. The user wants that selectable on "Eigene KI", like the provider for object recognition. Unlike that choice, a change here invalidates stored data: vectors of different models are not comparable. So a change must be clearly confirmed and must start recomputing all items.

## Goals

- A provider can carry an embedding model. One provider is active for search, chosen separately from the one active for object recognition. They may be the same.
- Switching tests the provider first, asks for confirmation, then recomputes every item with visible progress.
- The minimum similarity of semantic search is set per provider, because similarity values are not comparable between models.
- Keyword search works throughout. Semantic search never compares vectors of different models.

## Non-goals

- Vendors beyond Ollama, OpenAI and Mistral for embeddings. Claude offers none.
- A second vector column or a "switch in progress" state. Cancelling a recomputation. The way back is another switch.
- Automatic calibration of the similarity threshold, or per-model default values.
- A price estimate in the dialog: vendor prices are not reliably known.
- Changing the dimension (stays 1024).
- Changing the registration setting (see "Open before public deployment").

## Anti-goals

- **Silent mixing of models.** No path may leave vectors of an old model in place while queries use a new one.
- **Failover.** If the provider fails, the `.env` configuration does not step in; that would embed queries with another model than the stored vectors.
- **Cost without a decision.** An import of providers never activates anything and never starts a recomputation.
- **Keys in failures,** as for object recognition: embedding failures are scrubbed before they are reported or stored.

## Behaviour

### Provider fields

| Field | Rules |
|---|---|
| Modell für Bilderkennung (`model`) | Required for Claude. Otherwise required unless an embedding model is set. Cannot be emptied while the provider is active for object recognition |
| Embedding-Modell (`embedding_model`) | Not offered for Claude. Otherwise required unless a model for object recognition is set. Cannot be emptied while active for search |
| Mindest-Ähnlichkeit (`search_min_similarity`) | Shown with the embedding model, 0 to 1, prefilled 0.5, required with an embedding model. Changes apply to the next search and never recompute |

Both model fields offer the same unfiltered `ProviderModels` suggestions. The probe catches a wrong pick.

### Card

- Shows both models and a separate badge per role ("Aktiv" and "Suche aktiv").
- "Aktivieren" needs a model for object recognition; "Für Suche aktivieren" needs an embedding model. Each is hidden otherwise.
- A provider with either active role cannot be deleted.
- "Testen" checks every configured model. With both configured, the result names both, e.g. "Bilderkennung: ok. Embedding: Das Embedding-Modell von OpenAI liefert 768 statt 1024 Dimensionen."
- While items lack an embedding, the provider active for search shows "Suche wird neu aufgebaut: 412 von 1000" and the page polls. Both numbers are counted from `items`; a newly captured item shows there for a moment too.
- The last failure of a run appears on that card with the note that it is retried hourly.
- The page subheading names the `.env` fallback for each role that has no active provider.

### Switching

A switch is: the first activation for search (even if `.env` names the same model), another provider, or a changed embedding model on the active one.

**From "Für Suche aktivieren":**

1. Pressing it sends a short text to the provider and checks that the call succeeds and returns exactly 1024 dimensions.
2. On failure nothing changes and a notification gives the German reason. There is no way to skip this.
3. On success a dialog names the old and new model, the number of items, that semantic search stays incomplete until the end and that a paid provider costs money.
4. On confirmation, one transaction marks the provider active for search, empties all embeddings and clears the stored failure. `ReembedItems` is dispatched after commit.

**From the edit dialog of the provider active for search:** changing the embedding model shows a required confirmation checkbox with the same content inside the dialog, because a stacked dialog would have to carry the unsaved form, including a typed key, through action arguments. Saving then probes with the submitted values. On failure the dialog stays open and nothing is saved. On success the other fields are saved together with the switch.

These edits are not a switch: key, Ollama address (accepted risk: same model name on another host), name, model for object recognition, minimum similarity. The vendor is already fixed after creation.

### `.env` fallback

Without a provider active for search, `INVENTORY_EMBEDDING_PROVIDER`, `INVENTORY_EMBEDDING_MODEL` and `INVENTORY_SEARCH_MIN_SIMILARITY` apply unchanged, so existing vectors stay valid after the upgrade. After the first activation one provider is always active for search; the UI has no way back to `.env`. A local setup switches to an Ollama provider with `bge-m3` instead.

### Recomputing

`ReembedItems` (`$tries = 1`, `$timeout = 200`) works through items without an embedding for about 150 seconds, then dispatches itself with a cursor, so an import waits for at most one such stretch. After 3 failures in a row it stops; the hourly `items:embed` picks up the rest. `items:embed` and `EmbedItem` use the provider active for search as well, and the `.env` otherwise.

A run with failures stores the last reason in `ai_providers.search_failure`; a run without failures clears it.

A vector computed with the old model must not be stored after a switch. `embedAndStore()` locks the item row, then compares the current embedding target with the one it used. The switch's update of `items` waits for that lock, so either the old vector is dropped or the switch overwrites it.

### Search

The threshold comes from the current embedding target. Query vectors are cached for 10 minutes through the SDK's own embedding cache (`->cache(600)`): its key is a hash of driver, model, dimensions and input, it holds no plain text in the key and failures are not stored. Providers with the same vendor and model share entries, which is correct because their vectors are the same. The 3-second timeout and the keyword fallback stay. `extraction:eval` uses the same threshold source.

With a cloud provider, titles, descriptions, search terms and queries go to that vendor. The dialog does not repeat this; the same is already true of photos and object recognition.

### Export and import

The file gains `embedding_model` and `search_min_similarity`; `model` may be null. The rule from the form applies, and a violation fails the whole import. Files without the new fields stay importable, so `version` stays 1. Active roles are never transferred. Existing uuids are still skipped without change.

## Open before public deployment

Self-registration is on, so any verified registrant could trigger a switch and spend the stored key, in addition to the risk documented for the export. Decide this before going public; it is not part of this feature.

## Unverified at design time

- OpenAI's shortened 1024 dimensions have not been called live. The probe reveals a problem.
- 0.5 is only a starting value for other models and must be tuned on real data.
- That paging or filtering a result list repeats the query embedding is inferred from the code, not measured.
