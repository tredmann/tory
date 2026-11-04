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

- **Page:** a Filament custom page `app/Filament/Pages/ExtractItem.php`, navigation label "Erfassen", URL `/erfassen`. Form: one `FileUpload` field (images only, multiple, max 6 files, max 12 MB each, `storeFiles(false)` so nothing is copied into `storage/app`), a submit button "Analysieren". Below the form, once there is a result: the title (heading) and the description (paragraph). Submission is synchronous with Filament's loading state on the button.
- **Image size and latency:** photos are downscaled in the browser by Filament's FilePond resize options to at most 2048 px on the longest side before upload. This keeps labels legible, keeps each image well under Anthropic's 5 MB per-image limit in production, and bounds gemma4's response time locally. The extractor prompt uses a 180 s timeout, and `docker/php.ini` `max_execution_time` is raised to 300 s (image rebuild). If a real photo still times out, the next step is a queued job, which is out of scope here.
- **Agent:** `app/Ai/Agents/InventoryExtractor.php` implementing `HasStructuredOutput` with schema `{ title: string (required, short, German), description: string (required, German) }`. Instructions in German: the photos show one object to be inventoried; describe only what is visible; never invent identifiers; list printed text verbatim when legible; mention colour, connectors, material, condition; output a concise title (a few words) and a description of a few sentences or a compact feature list.
- **Provider/model switch is scoped to the extractor**, not the whole app: `config/ai.php` gains an `inventory` block with `provider => env('INVENTORY_AI_PROVIDER', 'anthropic')` and `model => env('INVENTORY_AI_MODEL')` (null means the provider's default model). The agent returns these from `provider()` / `model()`, so `ai.default` and the existing `Assistant` are untouched. `.env.example` documents `INVENTORY_AI_PROVIDER=ollama`, `INVENTORY_AI_MODEL=gemma4:e4b-mlx`, `OLLAMA_URL=http://host.docker.internal:11434` as the local setup, with Anthropic as the committed default.
- **Images to the agent:** the form state holds Livewire `TemporaryUploadedFile`s. They are handed to the prompt as `Image::fromPath($file->getRealPath())` (the temp disk is local, and `LocalImage` sniffs the real mime; `fromUpload()` without an explicit mime would send `application/octet-stream`). After the prompt returns, the page deletes the temporary files; nothing is stored permanently.
- **Errors:** any exception from the agent is `report()`ed and shown as a Filament danger notification ("Analyse fehlgeschlagen" plus the message when `APP_DEBUG` is on); the form keeps its state. A structured response that lacks `title` or `description` (the SDK returns an empty array when the model does not produce JSON) is treated the same way, not as a result.
- **Existing `Assistant` agent:** left untouched.
- **Tests (Pest):** page requires a verified user and renders; submit without images fails validation; submit with fake images calls the agent once with as many attachments as images and shows the faked title and description; a throwing agent shows the error notification and no result; an agent faked to return an empty/incomplete structure shows the error notification. Agent faked with `InventoryExtractor::fake([...])`; config switching covered by a test that the agent's `provider()`/`model()` reflect the `inventory` config.
- **No automated test against the real Ollama**; a manual check with a real photo is part of the acceptance.

## Left to the builder

- Exact prompt wording and schema field descriptions (iterate against gemma4 with a real photo until the output is factual and German).
- Page layout details within Filament's components.
- Whether to show the raw JSON for debugging when `APP_DEBUG` is on.

## Acceptance

1. Logged-in, verified user opens "Erfassen", uploads two photos of a Raspberry Pi, clicks "Analysieren", and within the model's response time sees a German title and description that mention only visible things.
2. With Ollama stopped, the same action shows an error notification, the page stays usable, and the exception is in the log.
3. `php artisan test`, Pint and PHPStan are green.
