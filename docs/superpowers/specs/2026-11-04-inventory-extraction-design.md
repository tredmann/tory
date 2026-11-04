# Inventory extraction from photos — design

Date: 2026-11-04. Status: draft for approval.

## Intent

Tory is going to be an inventory app. The first building block is the extraction step: the user uploads one or more photos of a single object (for example a Raspberry Pi), and an AI agent returns a German **title** and a German, search-friendly **description** of what is visibly in the photos. For now the result is only shown on screen. Saving it as an inventory item, editing it, and searching come later ("we will wire it to a model later").

## Goals

- A page in the Filament panel where the user uploads one or more images and gets a title and a description back.
- Output is German.
- The description helps find the item later: brand, model, printed text and labels, colour, ports/connectors, material, visible condition, anything a person would type into a search box.
- Works locally against the user's Ollama (`gemma4:e4b-mlx`, vision-capable, verified reachable from the app container at `http://host.docker.internal:11434`) and in production against a hosted provider, switched by configuration rather than code.

## Non-goals (for now)

- Persisting the result, an `Item` model, migrations, editing the result, search.
- Background/queued processing, progress streaming.
- Any other media than still images.

## Anti-goals (would make it a failure even if it "works")

- Invented details: a serial number, model name, brand or capacity the model cannot actually read in the photo. The agent describes only what is visible.
- Marketing prose ("a versatile single-board computer for makers"). The description is factual and specific.
- English output.
- A crash or blank page when Ollama is down or the model errors.

## Constraints

- Everything runs in Docker (`docker compose exec app ...`). Ollama runs on the host; inside the container it is `host.docker.internal:11434`.
- Stack: Laravel 13, Filament 5 panel at `/`, `laravel/ai` 1.3 (already installed, Anthropic is the configured default provider), Pest, Pint, Larastan.
- `laravel/ai` facts verified in the installed package: `prompt()` takes an array of attachments; `Image::fromPath()` / `fromUpload()` / `fromStorage()` exist; the Ollama gateway maps image attachments to Ollama's `images` field; an agent implementing `HasStructuredOutput` with a `schema()` makes Ollama return JSON via its `format` option and the SDK returns a `StructuredAgentResponse` with array access.
- Filament `FileUpload::make()->image()->multiple()` handles the upload; Livewire's temporary upload limit is 12 MB per file, PHP limits are 64 MB.

## Decided (by the user)

- Result is shown on the page, not stored.
- German title and description.
- Only what can really be seen; nothing invented.
- Local provider is Ollama with gemma4; one or more photos per object.

## Decided (my calls; say if wrong)

- **Page:** a Filament custom page `app/Filament/Pages/ExtractItem.php`, navigation label "Erfassen", URL `/erfassen`. Form: one `FileUpload` field (images only, multiple, max 6 files, max 12 MB each), a submit button "Analysieren". Below the form, once there is a result: the title (heading) and the description (paragraph). Submission is synchronous with Filament's loading state on the button.
- **Agent:** `app/Ai/Agents/InventoryExtractor.php` implementing `HasStructuredOutput` with schema `{ title: string (required, short, German), description: string (required, German) }`. Instructions in German: the photos show one object to be inventoried; describe only what is visible; never invent identifiers; list printed text verbatim when legible; mention colour, connectors, material, condition; output a concise title (a few words) and a description of a few sentences or a compact feature list.
- **Provider/model switch:** `config/ai.php` `'default'` becomes `env('AI_PROVIDER', 'anthropic')`; the extractor passes `model: config('ai.inventory.model')` which reads `env('AI_MODEL')` (null means the provider's default). `.env.example` documents `AI_PROVIDER=ollama`, `AI_MODEL=gemma4:e4b-mlx`, `OLLAMA_URL=http://host.docker.internal:11434` for local use, with Anthropic as the committed default.
- **Images to the agent:** the uploaded temporary files are passed as `Image` attachments on the single prompt; nothing is copied anywhere permanent. Livewire cleans its temporary uploads.
- **Errors:** any exception from the agent is `report()`ed and shown as a Filament danger notification ("Analyse fehlgeschlagen" plus the message when `APP_DEBUG` is on); the form keeps its state.
- **Existing `Assistant` agent:** left untouched.
- **Tests (Pest):** page requires a verified user and renders; submit without images fails validation; submit with fake images calls the agent once with as many attachments as images and shows the faked title and description; a throwing agent shows the error notification and no result. Agent faked with `InventoryExtractor::fake([...])`.
- **No automated test against the real Ollama**; a manual check with a real photo is part of the acceptance.

## Left to the builder

- Exact prompt wording and schema field descriptions (iterate against gemma4 with a real photo until the output is factual and German).
- Page layout details within Filament's components.
- Whether to show the raw JSON for debugging when `APP_DEBUG` is on.

## Acceptance

1. Logged-in, verified user opens "Erfassen", uploads two photos of a Raspberry Pi, clicks "Analysieren", and within the model's response time sees a German title and description that mention only visible things.
2. With Ollama stopped, the same action shows an error notification, the page stays usable, and the exception is in the log.
3. `php artisan test`, Pint and PHPStan are green.
