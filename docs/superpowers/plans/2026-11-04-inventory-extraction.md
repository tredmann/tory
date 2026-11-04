# Inventory Extraction from Photos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Filament page "Erfassen" where a verified user uploads 1–6 photos of one object and an AI agent returns a German title and search-friendly description, shown on the page.

**Architecture:** An `InventoryExtractor` agent (`laravel/ai`, structured output) owns the prompt, schema, provider/model/timeout. A Filament custom page owns the upload form, calls the agent synchronously with the temporary uploads as image attachments, shows the result or a danger notification, and deletes the temp files. Provider and model come from a new `ai.inventory` config block so the rest of the app stays on the global default.

**Tech Stack:** Laravel 13, Filament 5.11 (Schemas API), `laravel/ai` 1.3, Livewire 4, Pest. All commands run via `docker compose exec app ...`.

**Spec:** `docs/superpowers/specs/2026-11-04-inventory-extraction-design.md`

## Global Constraints

- Output language German; the agent describes only what is visible and never invents identifiers (spec "Anti-goals").
- Nothing is persisted: `storeFiles(false)`, temporary uploads deleted after the prompt.
- Page: label/title "Erfassen", slug `erfassen`, max 6 images, max 12 MB each, browser-side resize to ≤ 2048 px (`imageResizeMode('contain')`, no upscale).
- Prompt timeout 180 s; `docker/php.ini` `max_execution_time = 300`.
- Config: `ai.inventory.provider` = `env('INVENTORY_AI_PROVIDER', 'anthropic')`, `ai.inventory.model` = `env('INVENTORY_AI_MODEL')`. `ai.default` and `App\Ai\Agents\Assistant` untouched.
- Error copy: notification title `Analyse fehlgeschlagen`; body is the exception message only when `app()->hasDebugModeEnabled()`.
- Pint + PHPStan clean; every task ends with `php artisan test` green.

## Review Focus

1. A non-image file renamed to `.jpg` → rejected by validation with a message, no agent call (Task 2 test "rejects a non-image file").
2. Ollama unreachable / model missing → `ProviderConnectionException` / `RequestException` → notification, form state kept (Task 2 test "shows an error notification when the agent throws").
3. Model answers with prose or JSON missing `description` → notification, not a half result (Task 2 test "treats an incomplete structured answer as a failure").
4. Seven files or one file over 12 MB → validation message, no agent call (Task 2 test "rejects more than six images").
5. Slow model → the 180 s timeout from the agent is what the gateway receives (Task 1 test "uses a 180 second timeout").

---

### Task 1: `InventoryExtractor` agent and `ai.inventory` config

**Files:**
- Modify: `config/ai.php` (add `'inventory' => [...]` block after `'default_for_classification'`)
- Modify: `.env.example` (document local Ollama setup)
- Create: `app/Ai/Agents/InventoryExtractor.php`
- Test: `tests/Feature/Ai/InventoryExtractorTest.php`

**Interfaces:**
- Produces: `final class InventoryExtractor implements Agent, HasStructuredOutput` using `Laravel\Ai\Promptable`, with `instructions(): string`, `schema(JsonSchema $schema): array` returning keys `title` and `description` (both `->string()->required()->description(...)`), `provider(): string` (= `config('ai.inventory.provider')`), `model(): ?string` (= `config('ai.inventory.model')`), `timeout(): int` (= 180). Called as `InventoryExtractor::make()->prompt(string $prompt, array $attachments)`; the result is a `StructuredAgentResponse` whose `toArray()` gives `['title' => ..., 'description' => ...]`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Ai\Agents\InventoryExtractor;
use Laravel\Ai\Files\Image;

test('provider and model come from the inventory config', function () {
    config(['ai.inventory.provider' => 'ollama', 'ai.inventory.model' => 'gemma4:e4b-mlx']);
    $agent = new InventoryExtractor;

    expect($agent->provider())->toBe('ollama')
        ->and($agent->model())->toBe('gemma4:e4b-mlx');
});

test('the model falls back to the provider default when unset', function () {
    config(['ai.inventory.model' => null]);

    expect((new InventoryExtractor)->model())->toBeNull();
});

test('uses a 180 second timeout', function () {
    expect((new InventoryExtractor)->timeout())->toBe(180);
});

test('returns a structured title and description for the attached images', function () {
    InventoryExtractor::fake([
        ['title' => 'Raspberry Pi 4 Model B', 'description' => 'Grüne Platine mit vier USB-Anschlüssen.'],
    ]);

    $response = InventoryExtractor::make()->prompt('Beschreibe das Objekt.', [
        Image::fromBase64(base64_encode('a'), 'image/png'),
        Image::fromBase64(base64_encode('b'), 'image/png'),
    ]);

    expect($response->toArray())->toBe([
        'title' => 'Raspberry Pi 4 Model B',
        'description' => 'Grüne Platine mit vier USB-Anschlüssen.',
    ]);

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2);
});

test('instructions are German and forbid inventing details', function () {
    $instructions = (string) (new InventoryExtractor)->instructions();

    expect($instructions)->toContain('Deutsch')->toContain('sichtbar');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --no-ansi tests/Feature/Ai/InventoryExtractorTest.php`
Expected: FAIL with `Class "App\Ai\Agents\InventoryExtractor" not found`.

- [ ] **Step 3: Add the config block and env documentation**

In `config/ai.php`, after the `default_for_*` keys:

```php
'inventory' => [
    'provider' => env('INVENTORY_AI_PROVIDER', 'anthropic'),
    'model' => env('INVENTORY_AI_MODEL'),
],
```

In `.env.example`, under the existing AI keys, add commented local defaults:

```
# Inventory extraction (photos → title/description). Local: Ollama on the host.
INVENTORY_AI_PROVIDER=anthropic
INVENTORY_AI_MODEL=
# INVENTORY_AI_PROVIDER=ollama
# INVENTORY_AI_MODEL=gemma4:e4b-mlx
OLLAMA_URL=http://host.docker.internal:11434
```

- [ ] **Step 4: Implement `App\Ai\Agents\InventoryExtractor`**

Run `docker compose exec app php artisan make:agent InventoryExtractor --no-interaction`, then make it `implements Agent, HasStructuredOutput` (drop `Conversational`/`HasTools` and their methods), add `provider()`, `model()`, `timeout()` as in Interfaces, and `schema()`:

```php
public function schema(JsonSchema $schema): array
{
    return [
        'title' => $schema->string()->required()->description('Kurzer deutscher Titel des Objekts, wenige Worte.'),
        'description' => $schema->string()->required()->description('Deutsche, sachliche Beschreibung für die spätere Suche.'),
    ];
}
```

`instructions()` returns German text covering, in this order: the photos show one single object to be inventoried; describe only what is actually visible ("sichtbar"); never invent brand, model, serial number, capacity or anything not legible; quote printed text and labels verbatim when legible; mention colour, material, connectors/ports, size cues, visible condition and damage; write in German ("Deutsch"); produce a concise `title` of a few words and a `description` of a few factual sentences or a compact feature list, no marketing language. (Exact wording is the builder's; iterate against gemma4 in Task 3.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose exec app php artisan test --no-ansi tests/Feature/Ai/InventoryExtractorTest.php`
Expected: 5 passed.

- [ ] **Step 6: Lint, full suite, commit**

Run: `docker compose exec app ./vendor/bin/pint --no-ansi && docker compose exec app ./vendor/bin/phpstan analyse --no-ansi --no-progress && docker compose exec app php artisan test --no-ansi`
Expected: Pint PASS, `[OK] No errors`, all tests pass.

```bash
git add config/ai.php .env.example app/Ai/Agents/InventoryExtractor.php tests/Feature/Ai/InventoryExtractorTest.php
git commit -m "Add InventoryExtractor agent with inventory provider config"
```

---

### Task 2: "Erfassen" page with upload form, result and error handling

**Files:**
- Create: `app/Filament/Pages/ExtractItem.php`
- Create: `resources/views/filament/pages/extract-item.blade.php`
- Modify: `docker/php.ini` (`max_execution_time = 300`)
- Test: `tests/Feature/Panel/ExtractItemPageTest.php`

**Interfaces:**
- Consumes: `InventoryExtractor::make()->prompt(string, array<Image>)`, `->toArray()`; `InventoryExtractor::fake(array|Closure)`, `::assertPrompted(Closure)`, `::assertNeverPrompted()` (Task 1).
- Produces: `class ExtractItem extends Filament\Pages\Page` with `protected static ?string $slug = 'erfassen'`, `protected static ?string $title = 'Erfassen'`, `protected static ?string $navigationLabel = 'Erfassen'`, `protected string $view = 'filament.pages.extract-item'`; public state `?array $data = []`, `?string $extractedTitle = null`, `?string $extractedDescription = null` (not `$title`: Filament's page already declares a static `$title`); `mount(): void` (`$this->form->fill()`), `form(Schema $schema): Schema` (`statePath('data')`), `analyze(): void`. URL `/erfassen` behind the panel's auth + verified middleware (automatic for panel pages).

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Ai\Agents\InventoryExtractor;
use App\Filament\Pages\ExtractItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('the page is reachable for verified users', function () {
    $this->get('/erfassen')->assertOk()->assertSee('Erfassen');
});

test('guests are redirected to login', function () {
    auth()->logout();

    $this->get('/erfassen')->assertRedirect('/login');
});

test('submitting without images fails validation and never calls the agent', function () {
    InventoryExtractor::fake();

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => []])
        ->call('analyze')
        ->assertHasFormErrors(['photos']);

    InventoryExtractor::assertNeverPrompted();
});

test('rejects a non-image file', function () {
    InventoryExtractor::fake();

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('notes.jpg', 10, 'text/plain')]])
        ->call('analyze')
        ->assertHasFormErrors(['photos']);

    InventoryExtractor::assertNeverPrompted();
});

test('rejects more than six images', function () {
    InventoryExtractor::fake();

    $photos = collect(range(1, 7))->map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"))->all();

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => $photos])
        ->call('analyze')
        ->assertHasFormErrors(['photos']);

    InventoryExtractor::assertNeverPrompted();
});

test('shows the extracted title and description for the uploaded images', function () {
    InventoryExtractor::fake([
        ['title' => 'Raspberry Pi 4 Model B', 'description' => 'Grüne Platine, vier USB-Anschlüsse, Aufdruck "Raspberry Pi 4 Model B".'],
    ]);

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')]])
        ->call('analyze')
        ->assertHasNoFormErrors()
        ->assertSet('extractedTitle', 'Raspberry Pi 4 Model B')
        ->assertSee('Raspberry Pi 4 Model B')
        ->assertSee('Aufdruck "Raspberry Pi 4 Model B"');

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2);
});

test('shows an error notification when the agent throws', function () {
    Exceptions::fake();
    InventoryExtractor::fake(fn () => throw new RuntimeException('Connection refused'));

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg')]])
        ->call('analyze')
        ->assertNotified('Analyse fehlgeschlagen')
        ->assertSet('extractedTitle', null)
        ->assertSet('extractedDescription', null);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Connection refused');
});

test('treats an incomplete structured answer as a failure', function () {
    InventoryExtractor::fake([['title' => 'Nur ein Titel']]);

    Livewire::test(ExtractItem::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg')]])
        ->call('analyze')
        ->assertNotified('Analyse fehlgeschlagen')
        ->assertSet('extractedTitle', null);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --no-ansi tests/Feature/Panel/ExtractItemPageTest.php`
Expected: FAIL with `Class "App\Filament\Pages\ExtractItem" not found` (first test 404).

- [ ] **Step 3: Create the page class**

Run `docker compose exec app php artisan make:filament-page ExtractItem --panel=admin --no-interaction` (accept a plain custom page), then set the static properties from Interfaces and implement:

- `form(Schema $schema): Schema` → `$schema->components([ FileUpload::make('photos')->label('Fotos')->image()->multiple()->required()->minFiles(1)->maxFiles(6)->maxSize(12288)->storeFiles(false)->imageResizeMode('contain')->imageResizeTargetWidth('2048')->imageResizeTargetHeight('2048')->imageResizeUpscale(false)->reorderable()->panelLayout('grid') ])->statePath('data')`.
- `analyze(): void`: `$photos = $this->form->getState()['photos']` (array of `TemporaryUploadedFile`); reset `extractedTitle`/`extractedDescription` to null; build `$attachments = array_map(fn ($f) => Image::fromPath($f->getRealPath()), $photos)`; in `try { $result = InventoryExtractor::make()->prompt('Analysiere die Fotos und liefere Titel und Beschreibung.', $attachments)->toArray(); }` validate `filled($result['title'] ?? null) && filled($result['description'] ?? null)` else `throw new RuntimeException('Das Modell hat keine vollständige Antwort geliefert.')`; set the two properties; `catch (Throwable $e) { report($e); Notification::make()->title('Analyse fehlgeschlagen')->body(app()->hasDebugModeEnabled() ? $e->getMessage() : null)->danger()->send(); }`; `finally` delete each `TemporaryUploadedFile` (`$f->delete()`), wrapped so a delete failure cannot mask the result.

- [ ] **Step 4: Create the view**

`resources/views/filament/pages/extract-item.blade.php`:

```blade
<x-filament-panels::page>
    <form wire:submit="analyze" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="analyze">
            <x-filament::loading-indicator wire:loading wire:target="analyze" class="h-5 w-5" />
            Analysieren
        </x-filament::button>
    </form>

    @if ($this->extractedTitle !== null)
        <x-filament::section heading="{{ $this->extractedTitle }}">
            <p class="whitespace-pre-line">{{ $this->extractedDescription }}</p>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
```

- [ ] **Step 5: Raise the PHP execution limit**

In `docker/php.ini` set `max_execution_time = 300`, then `docker compose build app && docker compose up -d app`.
Verify: `docker compose exec app php -i | grep max_execution_time` → `300 => 300`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec app php artisan test --no-ansi tests/Feature/Panel/ExtractItemPageTest.php`
Expected: 8 passed. If `assertNotified` needs the Livewire notification container, check Filament's `TestsNotifications` helper and adjust the call, not the behaviour.

- [ ] **Step 7: Lint, full suite, commit**

Run: `docker compose exec app ./vendor/bin/pint --no-ansi && docker compose exec app ./vendor/bin/phpstan analyse --no-ansi --no-progress && docker compose exec app php artisan test --no-ansi`
Expected: PASS / `[OK] No errors` / all tests pass.

```bash
git add app/Filament/Pages/ExtractItem.php resources/views/filament/pages/extract-item.blade.php docker/php.ini tests/Feature/Panel/ExtractItemPageTest.php
git commit -m "Add Erfassen page that extracts title and description from photos"
```

---

### Task 3: Real-model smoke test, prompt tuning and docs

**Files:**
- Modify: `app/Ai/Agents/InventoryExtractor.php` (instructions wording only, if tuning is needed)
- Modify: `CLAUDE.md` (Conventions: extraction page, agent, env vars), `README.md` (AI section: local Ollama env lines)
- Modify: `.env` (local only, not committed): `INVENTORY_AI_PROVIDER=ollama`, `INVENTORY_AI_MODEL=gemma4:e4b-mlx`, `OLLAMA_URL=http://host.docker.internal:11434`

**Interfaces:**
- Consumes: Task 1 agent, Task 2 page.

- [ ] **Step 1: Point the local env at Ollama**

Add the three lines above to `.env`. Verify: `docker compose exec app php artisan tinker --execute='echo config("ai.inventory.provider"), " ", config("ai.inventory.model"), "\n";'` → `ollama gemma4:e4b-mlx`.

- [ ] **Step 2: End-to-end smoke test through the agent against the real model**

Run inside the container a Tinker script that generates a small PNG with recognisable content (a solid red square is enough to prove the pipeline; a photo is better), calls `InventoryExtractor::make()->prompt('Analysiere die Fotos und liefere Titel und Beschreibung.', [Image::fromPath($path)])->toArray()` and prints it.
Expected: an array with German `title` and `description`, no exception, within ~30 s. If the output is English or invents details, tighten `instructions()` and rerun; keep Task 1's instruction test green.

- [ ] **Step 3: Failure path against the real stack**

Run the same script with `config(['ai.providers.ollama.url' => 'http://host.docker.internal:1'])` (unreachable port).
Expected: an exception is thrown (`ProviderConnectionException` or similar), which the page turns into the notification (covered by Task 2 tests).

- [ ] **Step 4: Browser acceptance (user)**

Open http://localhost:8000/erfassen as a verified user, upload one or more real photos of an object, click "Analysieren".
Expected: German title and description appear below the form that mention only visible things; `storage/app/private/livewire-tmp` does not retain the photos afterwards. (The Claude in Chrome extension cannot load localhost here; this step is done by the user.)

- [ ] **Step 5: Docs**

`CLAUDE.md` Conventions: add a bullet for the extraction feature (page `app/Filament/Pages/ExtractItem.php`, agent `app/Ai/Agents/InventoryExtractor.php`, env `INVENTORY_AI_PROVIDER`/`INVENTORY_AI_MODEL`/`OLLAMA_URL`, nothing persisted yet). `README.md` AI section: the three local env lines and that the local model is Ollama `gemma4:e4b-mlx` reached via `host.docker.internal`.

- [ ] **Step 6: Commit**

```bash
git add app/Ai/Agents/InventoryExtractor.php CLAUDE.md README.md
git commit -m "Tune extractor instructions and document the Erfassen workflow"
```
