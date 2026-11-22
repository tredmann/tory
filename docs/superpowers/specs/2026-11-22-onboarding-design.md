# Onboarding — design

Date: 2026-11-22. Status: approved (PRD). Ticket: TOR-49. PRD (German): https://kb.balt.io/doc/onboarding-auf-der-ubersicht-ki-nur-uber-eigene-ki-Dvuo2Mrmvg

## Intent

Tory is public on GitHub, so it is now installed by people who have never seen it. After the first administrator setup (spec first-administrator-setup) they land on an empty overview and have to guess what comes next: which language the AI writes in, that object recognition needs a provider under "Eigene KI", what the difference between a location and a spot is, and how capturing works. That spec named "guided Eigene KI, a 'no AI set up yet' hint on the overview" as a later feature; this is it.

Two parts:

1. **Onboarding on the overview** for administrators: a checklist of the five things to do before Tory is useful (five steps), each with a sentence on why.
2. **AI only through "Eigene KI".** The `.env` fallback for providers and its built-in defaults go away. Today a fresh installation seems to have an AI (`anthropic` for recognition, `ollama`/`bge-m3` for search) even when neither works; afterwards "no provider active" honestly means "no AI set up", and the onboarding can tell.

## Goals

- A new administrator sees on the overview what is left to set up, in a recommended order, and gets to each page in one click.
- Every step explains itself in one or two sentences, for someone who does not know Tory.
- Without an active provider, Tory says plainly that no AI is set up, instead of calling a vendor without credentials.
- The page "Eigene KI" shows per role (object recognition, search) whether a provider is active.

## Non-goals

- Onboarding for plain users. They see the overview as today.
- Inviting further users as a step.
- Testing the provider from the overview (no AI call on page load: each one may cost money).
- A way to bring the onboarding back once it is gone.

## Anti-goals

- An existing household (such as the maintainer's own installation) sees the onboarding after the upgrade.
- A step is ticked although Tory cannot actually do it (the reason the `.env` defaults go).
- The order is enforced: a step done early is ticked, every step stays reachable.

## Approaches

1. **A widget of its own with a Blade view and plain CSS (chosen).** `OnboardingWidget` renders the list itself, its look lives in `resources/css/onboarding.css`, as `item-grid.css` does for the item grid (the panel uses Filament's precompiled theme, so uncompiled Tailwind classes do not exist). "Passt so" and "Ausblenden" are Filament actions in the widget. Allows exactly the agreed look; costs some CSS that must work in light and dark mode.
2. **Filament components only** (`Section`, `TextEntry` rows, icons, actions). Less code and themed for free, but Filament has neither a progress bar nor a highlighted row; both are the parts the user liked most. Rejected.
3. **A page of its own (`/onboarding`) or a table of steps.** Rejected: the user wants it on the overview, and five fixed steps need no table.

## Decisions

### The steps

Steps are computed by a class `App\Onboarding\Onboarding` (builder's call on the namespace) that returns step DTOs: title, whether done, link, an optional result line and, for the open step, the explanation. In this order:

1. **Inhaltssprache prüfen** (check the content language). Done once `household_settings.content_language_confirmed_at` is set: by the action "Passt so" in the step, or by saving the page „Haushalt“. Result line: the language, e.g. "Deutsch". The first administrator setup already sets the content language from the browser, so this step is about looking, not choosing. Open step shows the current language, the button "Passt so" and the link "Ändern" to „Haushalt“.
2. **KI für die Objekterkennung einrichten** (set up AI for object recognition). Done when a provider is active for object recognition (`AiProvider::active()`). Result line: its name, e.g. "Claude". Link: "Eigene KI". The explanation says that this AI writes title, description and search terms from the photos.
3. **KI für die Suche einrichten** (set up AI for search). Done when a provider is active for search (`AiProvider::activeForSearch()`). It may be the same provider as in step 2 or another one. Result line: its name, e.g. "Ollama". Link: "Eigene KI". The explanation says that this AI lets search find things by meaning ("Bohrmaschine" finds "Akkuschrauber"), and that Claude cannot do it (`AiVendor::supportsEmbeddings()` is false for Anthropic), so with Claude for recognition search needs OpenAI, Mistral or Ollama.
4. **Ersten Lagerplatz anlegen** (create the first spot). Done when at least one spot exists. One step for location and spot: a spot cannot exist without a location, and the spot form creates a location inline (`createOptionForm`). The explanation tells the two apart (a location is an area such as "Keller", a spot the place in it such as "Regal 3, Fach 2", and every spot gets a printable label with a QR code). Link: spot create page.
5. **Ersten Gegenstand erfassen** (capture the first item). Done when at least one item exists; an open import does not count. Link: "Erfassen" (import create page).

The open step is the first one not done. The order is a recommendation, not a lock.

### Look (agreed mockup: mix of A and C)

A `Section`-style box above the search, titled "Tory einrichten" ("Set up Tory"), with "Ausblenden" in its header.

```
Tory einrichten                                    Ausblenden
3 von 5 erledigt
[████████████░░░░░░░░]
 ✓  Inhaltssprache prüfen · Deutsch
 ✓  KI für die Objekterkennung einrichten · Claude
 ✓  KI für die Suche einrichten · Ollama
┌──────────────────────────────────────────────────────────┐
│ 4  Ersten Lagerplatz anlegen                              │
│    Ein Lagerort ist ein Bereich wie „Keller“, …           │
│    [Lagerplatz anlegen]                                   │
└──────────────────────────────────────────────────────────┘
 5  Ersten Gegenstand erfassen
```

- Progress text and bar from mockup A.
- The open step has a highlighted background (Tory's primary colour, light); only it shows its explanation, with its button below the text (mockup C).
- Ticks and numbers stand in one column; the highlight does not indent the open step.
- Done steps are one line with their result; later steps show only their title.
- Every step title is a link to its page, done or not.
- The other widgets (search, open imports, locations, recent items) stay below, as today.

### When it shows and when it goes

- Only for administrators, and only while `household_settings.onboarding_finished_at` is null.
- When all five steps are done, `onboarding_finished_at` is set and the onboarding is gone for good. Builder's call where this is written (when the widget renders and finds all steps done is enough).
- "Ausblenden" sets `onboarding_finished_at` too, with a confirmation. It applies to the household, i.e. every administrator.
- The migration sets `content_language_confirmed_at` and `onboarding_finished_at` on installations that already have an item, so existing households never see it.

### AI only through "Eigene KI"

- Remove `ai.inventory.provider`, `model`, `embedding_provider`, `embedding_model` and `search_min_similarity` from `config/ai.php`, the `?: 'anthropic'` defaults in `InventoryExtractor` and `PhotoBoundaryChecker`, and the variables from `.env.example`. The search threshold default stays `AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY`, which `SearchProviderSwitch` already writes on activation.
- `EmbeddingTarget` loses its `.env` branch; without a provider active for search there is no target (builder's call how: nullable `current()` or a check before).
- Without a provider for object recognition: `ExtractImport` and `RegenerateTexts` fail at once, without an AI call, with the failure reason "Keine KI für die Objekterkennung eingerichtet" and a pointer to "Eigene KI". The import is still created and can be retried through the existing retry once a provider is active (my call; blocking "Erfassen" up front is the alternative).
- `GroupBulkImport` has no failed state and no retry: on any error it calls `BulkImport::finishGrouping(true, $failure)` and leaves the bulk import reviewable with the time-based cuts only. It keeps that path without an AI call, and the review page shows the same reason. Confirming stays allowed; the resulting imports fail as above and can be retried (my call: no new status or retry flow for bulk imports).
- Without a provider for search: search uses keyword matches only (as when embedding a query fails today); `items:embed` and `EmbedItem` skip without failing.
- "Eigene KI": the `.env` subheadings (`ManageAiProviders::getSubheading()`) become "Noch keine KI für die Objekterkennung aktiv." / "… für die Suche …" / both. The " (.env)" suffix in `AiProvidersTable` goes.
- `extraction:eval` already requires `--provider`; unchanged.
- Local development: once, create an Ollama provider (`gemma4:e4b-mlx`, embeddings `bge-m3`) under "Eigene KI". Tests that set `ai.inventory.*` move to `AiProvider` factories.

### Upgrade

No migration turns `.env` values into providers. The only known installation (the maintainer's) runs on one OpenAI provider active for both roles and uses no `.env` AI settings. The release notes say that AI is now set up only under "Eigene KI". Vectors stored under an `env:` identity simply match no target any more, which is the existing behaviour for a stale target.

## Constraints

- No AI call on page load.
- Translations: every new string in `lang/de.json`; `TranslationsTest` and `EnglishInterfaceTest` stay green.
- Panel CSS is plain CSS loaded through the `HEAD_END` hook, in light and dark mode.
- Invariant "nothing switches AI providers on its own" stays; it gets simpler (no `.env` to fall back to).

## Documentation to update in the same branch

- `CONTEXT.md`: add **Onboarding** (German UI „Tory einrichten“).
- `CLAUDE.md`: architecture map entry for the onboarding; remove the `.env` fallback from "AI providers" and the Ollama `.env` lines; local setup now means creating an Ollama provider.
- `README.md`: the `.env` AI section (lines on `INVENTORY_*`).
- Specs ai-provider-settings and selectable-embedding-provider describe the `.env` fallback; add a line pointing to this spec.

## Left to the builder

- Namespace and names of the onboarding class and its DTO.
- Exact explanation texts (German and English), in the tone of the existing UI.
- Exact colours of highlight and bar within Filament's palette.
