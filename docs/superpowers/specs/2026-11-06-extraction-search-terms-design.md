# Better extraction, search terms and an eval — design

Date: 2026-11-06. Status: draft for approval. Builds on `2026-11-06-items-locations-search-design.md` and `2026-11-06-ai-provider-settings-design.md`.

## Intent

Tory answers "where is that thing?", so an extracted text is good when the user finds the item with the words they would actually type. Today's texts describe the photo instead of the thing. The user's trigger: the RØDE Wireless GO was saved with "Verpackung" in its title, damage to the box in its description and features copied from the box. In their words, the description "sollte halt den potenziellen Gegenstand beschreiben und nicht, ob es eine Verpackung ist".

The current prompt asks for exactly this: "ausschließlich, was sichtbar ist" and "sichtbaren Zustand und Beschädigungen". The stored data shows a second, bigger failure: the word people search for is often missing.

| Item | Stored text | Problem |
|---|---|---|
| RØDE Wireless GO | "…Verpackung", box damage, box print | describes the box |
| APC | "Schwarzes Netzteil Gehäuse" | wrong kind of thing (it is a UPS, "USV") |
| Raspberry Pi 5 | "Platine", chip part numbers, some misread | details instead of purpose |
| Pelikan 4001 | "blaue und weiße Verpackung", "Best Quality" | describes the box, copies marketing |
| Soennecken 3155 | "Metallklammer", "Metallbürostapler" | wrong title, "Hefter" missing |

The stored data does not record which model wrote which text.

This round has three parts: a new prompt, search terms as a field of their own (AI-suggested tags the user can remove), and a small eval that measures the result with the user's own search words. The eval runs against local Gemma first and against OpenAI only at the end.

## Goals

- Titles and descriptions name the thing, not its packaging or its state in the photo.
- The agent pre-fills search terms; the user reviews them as tags and removes the wrong ones with a click.
- Search finds items through their terms, by keyword and by meaning.
- One command measures a provider against fixed test cases, so prompt changes are compared with numbers and not by impression.

## Non-goals

- Automatic backfilling. Existing items and imports keep their texts and have no terms until someone edits them or presses "Neu generieren". Their embeddings stay valid.
- "Alle neu generieren", because each press costs real money on a paid provider.
- A preview of regenerated texts to accept or reject. The user chose a confirmation dialog followed by a direct overwrite: "Aktuell ist hier auch nichts im System, was nicht weg kann. Außer die Provider".
- Fixing the search itself for spelling variants (normalising "Ø", splitting "pi5"). The terms carry the variants.
- Removing a tag directly on the item view page. The user chose variant A: the view page shows tags, "Bearbeiten" changes them. This can be added later.
- Per-model prompts. All providers get the same prompt.
- Tags as a shared vocabulary (tag table, filter by tag, autocomplete). Terms are plain strings per record.
- Running the eval in CI or in the normal test suite.

## Anti-goals

- Invented facts: brand, model, serial number or capacity that cannot be read in the photos.
- A prompt that overshoots: real packaging must still be called packaging. An empty moving box is a "Karton".
- An eval that spends OpenAI money by accident.
- An eval that writes into the `tory` database or touches items, imports or stored photos.

## Prompt rules

What the new prompt must express; the wording is tuned with the eval.

- **Kind of thing:** name what the object is, also when this is not printed anywhere. A stapler is a "Hefter" even if the photo only says "Office 3155". Recognising the kind is wanted; this replaces "ausschließlich, was sichtbar ist".
- **Identity:** brand, model, serial number and capacity come only from what is readable, copied exactly. "Erfinde niemals" stays for these.
- **Packaging:** a product package is a hint at its content, and the content is described. Only if the packaging itself is the object is it called so.
- **Title:** brand, model and kind where known. For example "Soennecken Office 3155 Hefter".
- **Description:** one or two sentences on what it is and what it is for, plus colour or material when they tell it apart. No feature lists from the box, no marketing, no chip part numbers.
- **Condition:** left out, unless the thing is obviously defective.
- **Search terms:** words a person would type: synonyms, the general category, and spelling variants without special characters (`RØDE` → `Rode`, `Raspberry Pi 5` → `Pi5`). Variants matter because keyword search matches exact letter sequences: "rode" does not find "RØDE" and "pi5" does not find "Pi 5".
- Output stays German, and stays a bare JSON object (the existing instruction for `gemma4`).

Colour stays allowed: the user says it sometimes helps and sometimes is what they search for.

## Search terms

- **Storage:** a `search_terms` `jsonb` column on `imports` and `items`, not null, default empty array, array cast. `jsonb` so that its text elements hold real umlauts and `Ø` for `ilike`, and can be matched one by one.
- **Extractor:** the schema gets a required array of strings. `extract()` returns a DTO (`title`, `description`, `searchTerms`) instead of an array, following the DTO convention; callers are `ExtractImport` and `ProviderTester`. Terms are trimmed, emptied ones dropped and duplicates removed case-insensitively. Missing or unusable terms produce an empty list and do not fail the import: title and description are what an import needs, and the eval makes missing terms visible.
- **Import flow:** `ExtractImport` stores the terms; `failed()` and "Erneut versuchen" clear them as they clear title and description. The import view page shows them. "Übernehmen" shows them in a Filament `TagsInput` labelled "Suchbegriffe", prefilled and editable, and `convertToItem()` takes them over.
- **Item pages:** view page shows badges (placeholder "—" when empty), edit page has the same `TagsInput`.
- **Keyword search:** `ItemSearch` also matches each element of `search_terms`, not the JSON text (so `[` or `,` do not match everything), with the same every-word rule across title, description and terms.
- **Semantic search:** `ItemEmbedder::textFor()` appends the terms, and `Item`'s events treat `search_terms` like title and description (clear the embedding, dispatch `EmbedItem`). With empty terms the text must equal today's text, so existing embeddings stay valid and need no recompute.
- **Risk to watch:** a word list may dilute the embedding of the text, or improve it. The eval shows it because it reports keyword and semantic hits apart.

## Regenerate

The user wants one button to generate the texts again, search terms included.

- **Where:** an action "Neu generieren" on the view page of items and of completed imports. Failed imports keep "Erneut versuchen".
- **What:** title, description and search terms are replaced, using `AiProvider::active()` and the existing `.env` fallback, on the record's stored photos. Hidden when a record has no photos.
- **Confirmation:** a dialog says that the current texts and tags, including manual changes, are replaced, and that this may cost money with a paid provider. There is no undo.
- **Background:** it runs as a queued job, since extraction can take three minutes. It follows the queue timing invariant (`$tries = 1`, `$timeout = 200`). The view page shows "Wird neu generiert …" and polls with `wire:poll.5s`, like imports do.
- **While running:** "Neu generieren", "Bearbeiten" and "Übernehmen" are hidden on that record, so a finished job cannot overwrite a fresh edit and a conversion cannot pull the row from under the job. A record deleted meanwhile ends the job quietly.
- **Failure:** the old texts and tags stay. This differs from `ExtractImport::failed()`, which clears them, so a completed import must not simply go through that path. The page shows the reason the way imports do (`ProviderFailureReason` sentence with the link to "Eigene KI", the raw error only with `app.debug`). A hard kill must not leave a record stuck in "running": `failed()` clears the state once the queue gives up, as for imports. The next successful run clears the error.
- **State:** items have no status today and need one for this (running flag and last error, failure reason and provider); the shape is left to the builder, with the failure fields of `imports` as the model.
- **Embedding:** an item's changed text re-embeds through the existing model events. `EmbedItem` runs afterwards as usual.
- **Eval:** the button uses the same extractor and prompt as the eval and needs no eval case of its own.

## Eval

`php artisan extraction:eval --provider="Ollama lokal"` (name to be confirmed by the builder against the command naming in the repo).

- **Provider:** `--provider` names a stored `AiProvider` and is required. There is no default and no fallback to the active provider, which is currently OpenAI (`gpt-6-luna`). An unknown name fails with a list of the available names.
- **Test cases:** under `tests/Evals/extraction/`, one definition file plus photos, independent of `storage/`. Adding a case means adding photos and an entry, without code. The normal suite does not run it.
- **Case fields:** photos, search words, words the title must contain, forbidden words. Forbidden words are per case and never global, since "Karton" is right for a box. They match as whole words or phrases, case-insensitive, in title and description only: "Platine" must not hit "Einplatinencomputer", and a synonym in the search terms is allowed because the user can remove it with a click.
- **Extraction:** calls the real `InventoryExtractor` with the same photos a user would upload.
- **Found:** each search word is checked two ways, reported apart: keyword (case-insensitive substring in title, description or terms, as `ItemSearch` does) and semantic (cosine similarity between the word and the item text from `textFor()`, using `bge-m3` and `ai.inventory.search_min_similarity`). A word is found when either hits.
- **False hits:** each search word is also checked against every other case's result. "hefter" must not find the microphone.
- **Report:** a table per case and a total, followed by the full generated texts because the user judges those by reading. The same report is saved under the gitignored `storage/app/private/evals/` with date and provider, to compare runs.
- **Failures:** one failing case is shown as failed and the rest still run. The exit code is non-zero only when nothing could run (unknown provider, embedding provider down). A low score is a result, not an error.
- **Repeats:** `--runs=N`, default 1, because model output varies. Totals show the range.
- **Writes nothing** to the database and calls no job.

### Test cases

Search words are the user's own. Title words and forbidden words are my proposal.

| Case | Photos | Search words | Title contains | Forbidden |
|---|---|---|---|---|
| RØDE Wireless GO | 3 | mikrofon, rode | Mikrofon | Verpackung, Karton, Gebrauchsspuren, beschädigt |
| APC | 1 | apc, usv, strom, steckdose | USV | Netzteil, Gehäuse |
| Raspberry Pi 5 | 1 | raspberry, pi5 | Raspberry Pi 5 | Platine |
| Pelikan 4001 | 1 | patronen, tinte | Tintenpatronen | Verpackung, Packung, Best Quality |
| Soennecken 3155 | 2 | hefter, tacker, klammer | Hefter | Metallklammer, Gebrauchsspuren |

The photos are the user's existing ones from `storage/app/private/imports/`, copied into the fixture folder and committed. `test.jpg` and `nope.jpg` are excluded. A sixth case, a real cardboard box with "karton, umzugskarton", guards against the overshoot anti-goal. It is added when the user supplies a photo and does not block this work.

## Order of work

1. Build the eval and search-term plumbing, with the prompt untouched. Run against Gemma for a baseline of the old prompt (terms stay empty).
2. Rewrite the prompt and iterate against Gemma, using the numbers and the texts.
3. When Gemma looks right, show the user the results and get their go before one run against OpenAI. This is the only step that costs money.
4. The user judges the texts. They decide when the prompt is done.

## Success

The user reads the generated texts and is happy with them, and this has the last word over the numbers. As a guide, on OpenAI: every search word found, no forbidden word, all title words present, no false hit. Gemma is the cheap test bench and may not reach this; the prompt is not bent to one model.

## Constraints

- Everything runs in Docker (`CLAUDE.md`). `bge-m3` and `gemma4:e4b-mlx` are installed on the host; the "Ollama lokal" provider points to `http://host.docker.internal:11434`.
- Conventions apply: DTOs, constructor injection, failing test first, Pint and PHPStan. Automated tests use fakes (`InventoryExtractor::fake`, `Embeddings::fake`), never a real model.
- `CLAUDE.md` is updated for the column, the prompt rules and the eval command.

## Calls I made (the user did not decide these)

- Condition is dropped unless obviously defective. The user was unsure; it is my recommendation.
- Missing terms do not fail an import.
- Existing items and imports are not backfilled automatically.
- "Neu generieren" exists on items and completed imports, and runs in the background; on failure the old texts stay.
- The photos are committed. The repo has no remote today. The APC photo shows the device's label with serial number and QR code, so reconsider before the repo goes public.
- Title words and forbidden words in the table above, and forbidden words being checked as whole words outside the search terms.
- The success guide above.

## Left to the builder

Command name and class layout, format of the case file, report formatting, how `--runs` totals are displayed, and the exact prompt wording within the rules above.
