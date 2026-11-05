# Asynchronous Photo Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the synchronous "Erfassen" page with an `Import` record whose photos are stored permanently, whose extraction runs in a queue job, and whose status and result are shown on a self-refreshing Filament view page.

**Architecture:** A string-backed `ImportStatus` enum and an `Import` model (photos as JSON paths on the private `local` disk). A Filament resource `ImportResource` (slug `importe`) with list, create and view pages; the create page stores the photos, creates the import as `pending` and dispatches `ExtractImport`. The job marks the import `running`, calls the unchanged `InventoryExtractor` with `Image::fromStorage()` attachments, and writes the result or, via `failed()`, the error. The view page polls every 5 s while pending/running and offers Retry when failed.

**Tech Stack:** Laravel 13, Filament 5.11 (Schemas API, resources), Livewire 4, `laravel/ai` 1.3, database queue, Pest 4, Pint, Larastan. All commands run as `docker compose exec app ...`.

**Spec:** `docs/superpowers/specs/2026-11-05-async-import-design.md`

## Global Constraints

- Every PHP command runs inside Docker: `docker compose exec app php artisan ...`, `docker compose exec app ./vendor/bin/pint --dirty`, `docker compose exec app ./vendor/bin/phpstan analyse`. Pint and PHPStan must be clean before each commit.
- Commits on `master` (there is no remote); commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` and use `git -c core.hooksPath=/dev/null commit`.
- `InventoryExtractor` (`app/Ai/Agents/InventoryExtractor.php`) and `config/ai.php` are not changed.
- Photos: disk `local`, directory `imports`, visibility `private`; accepted types `image/jpeg, image/png, image/webp, image/gif`; 1–6 files; 12288 KB per file; browser resize `contain` to 2048×2048 without upscaling; validation message for `mimetypes`: `Nur JPEG-, PNG-, WebP- oder GIF-Bilder sind erlaubt.`
- Enum labels/colours: Pending "Wartet"/gray, Running "Läuft"/warning, Completed "Fertig"/success, Failed "Fehlgeschlagen"/danger.
- Job: `$tries = 1`, `$timeout = 200`; prompt text `Analysiere die Fotos und liefere Titel und Beschreibung.`; incomplete answer message `Das Modell hat keine vollständige Antwort geliefert.`
- Generic error text shown when debug is off: `Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.`
- Retry notification title: `Erneut gestartet`. Create page title: `Erfassen`. Resource labels: model "Import", plural "Importe", navigation "Importe", slug `importe`.
- `DB_QUEUE_RETRY_AFTER=300` in `.env.example` and in the `app` service `environment` of `compose.yaml`; supervisord queue programs use `--timeout=210` and `stopwaitsecs=230` in both `docker/supervisor/dev.conf` and `prod.conf`.
- Tests: Pest, Feature tests get `RefreshDatabase` from `tests/Pest.php`; use `Storage::fake('local')` and `Queue::fake()` unless the job itself is under test; panel pages via `Livewire::test(PageClass::class, [...])`.
- Every verified user may list, create, view and retry imports; no policy.

## Review Focus

1. A photo file missing from disk when the job runs (deleted by hand): the import must end `failed` with the message, never loop. Test in Task 2.
2. The status changes in the database while the page is open: the next poll must show the new status and result without reload. Test in Task 4 (`$refresh` after updating the record).
3. Retry must not be reachable for pending, running or completed imports, even by calling the action directly. Test in Task 4.
4. With debug off, the raw exception text must not reach the page. Test in Task 4.
5. The worker must never hand a running extraction to a second worker: `retry_after` (300) above the job timeout (200). Verified in Task 2 via `config:show` inside the container and the `$timeout`/`$tries` test.

---

### Task 1: ImportStatus enum, Import model, migration, factory

**Files:**
- Create: `app/Enums/ImportStatus.php`, `app/Models/Import.php`, `database/migrations/<timestamp>_create_imports_table.php`, `database/factories/ImportFactory.php`
- Test: `tests/Feature/Models/ImportTest.php`

**Interfaces:**
- Produces: `App\Enums\ImportStatus: string` with cases `Pending = 'pending'`, `Running = 'running'`, `Completed = 'completed'`, `Failed = 'failed'`; implements `Filament\Support\Contracts\HasLabel` and `HasColor` (`getLabel(): string`, `getColor(): string`); method `isActive(): bool` true for Pending and Running.
- Produces: `App\Models\Import` with `$fillable` `user_id, status, photos, title, description, error`; casts `status => ImportStatus::class`, `photos => 'array'`; relation `user(): BelongsTo<User, $this>`; `ImportFactory` default state pending with one `User::factory()` and `photos => ['imports/a.jpg', 'imports/b.jpg']`; states `pending()`, `running()`, `completed()` (sets `title => 'Raspberry Pi 4'`, `description => 'Grüne Platine mit vier USB-Anschlüssen.'`), `failed()` (sets `error => 'Connection refused'`).

- [ ] **Step 1: Generate the files**

```bash
docker compose exec app php artisan make:enum ImportStatus --string --no-interaction
docker compose exec app php artisan make:model Import --migration --factory --no-interaction
```

- [ ] **Step 2: Write the failing tests** in `tests/Feature/Models/ImportTest.php`

```php
use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\User;

test('status labels and colours', function () {
    expect(ImportStatus::Pending->getLabel())->toBe('Wartet')
        ->and(ImportStatus::Running->getLabel())->toBe('Läuft')
        ->and(ImportStatus::Completed->getLabel())->toBe('Fertig')
        ->and(ImportStatus::Failed->getLabel())->toBe('Fehlgeschlagen')
        ->and(ImportStatus::Pending->getColor())->toBe('gray')
        ->and(ImportStatus::Running->getColor())->toBe('warning')
        ->and(ImportStatus::Completed->getColor())->toBe('success')
        ->and(ImportStatus::Failed->getColor())->toBe('danger');
});

test('pending and running are active, completed and failed are not', function () {
    expect(ImportStatus::Pending->isActive())->toBeTrue()
        ->and(ImportStatus::Running->isActive())->toBeTrue()
        ->and(ImportStatus::Completed->isActive())->toBeFalse()
        ->and(ImportStatus::Failed->isActive())->toBeFalse();
});

test('an import casts its status and photos and belongs to a user', function () {
    $import = Import::factory()->failed()->create();

    expect($import->fresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->fresh()->photos)->toBe(['imports/a.jpg', 'imports/b.jpg'])
        ->and($import->fresh()->error)->toBe('Connection refused')
        ->and($import->user)->toBeInstanceOf(User::class);
});

test('factory states set the matching status', function () {
    expect(Import::factory()->create()->status)->toBe(ImportStatus::Pending)
        ->and(Import::factory()->running()->create()->status)->toBe(ImportStatus::Running)
        ->and(Import::factory()->completed()->create())
            ->status->toBe(ImportStatus::Completed)
            ->title->toBe('Raspberry Pi 4');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/ImportTest.php`
Expected: FAIL (methods `getLabel`/`isActive` missing, table `imports` missing or factory states undefined)

- [ ] **Step 4: Implement** the enum, the migration (`id`, `foreignId('user_id')->constrained()->cascadeOnDelete()`, `string('status')`, `json('photos')`, `string('title')->nullable()`, `text('description')->nullable()`, `text('error')->nullable()`, `timestamps()`), the model and the factory as the Interfaces block says.

- [ ] **Step 5: Run the tests to verify they pass, then Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/ImportTest.php && docker compose exec app ./vendor/bin/pint --dirty && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: 4 passed; Pint and PHPStan clean

- [ ] **Step 6: Commit**

```bash
git add app/Enums app/Models/Import.php database tests/Feature/Models
git -c core.hooksPath=/dev/null commit -m "Add Import model with status enum, migration and factory"
```

---

### Task 2: ExtractImport job and queue limits

**Files:**
- Create: `app/Jobs/ExtractImport.php`
- Modify: `.env.example` (queue section), `compose.yaml` (`app` service `environment`), `docker/supervisor/dev.conf:20-25`, `docker/supervisor/prod.conf:20-25`
- Test: `tests/Feature/Jobs/ExtractImportTest.php`

**Interfaces:**
- Consumes: `Import`, `ImportStatus` from Task 1; `InventoryExtractor::make()->prompt(string, array $attachments)->toArray()`; `Laravel\Ai\Files\Image::fromStorage(string $path, ?string $disk)`.
- Produces: `App\Jobs\ExtractImport implements ShouldQueue` with `public function __construct(public Import $import)`, `public int $tries = 1`, `public int $timeout = 200`, `handle(): void`, `failed(?Throwable $exception): void`. Dispatched by Tasks 3 and 4 with `ExtractImport::dispatch($import)`.

- [ ] **Step 1: Generate the job**

```bash
docker compose exec app php artisan make:job ExtractImport --no-interaction
```

- [ ] **Step 2: Write the failing tests** in `tests/Feature/Jobs/ExtractImportTest.php`

```php
use App\Ai\Agents\InventoryExtractor;
use App\Enums\ImportStatus;
use App\Jobs\ExtractImport;
use App\Models\Import;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/a.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
    Storage::disk('local')->put('imports/b.jpg', Storage::disk('local')->get('imports/a.jpg'));
});

test('declares one attempt and a 200 second timeout', function () {
    $job = new ExtractImport(Import::factory()->create());

    expect($job->tries)->toBe(1)->and($job->timeout)->toBe(200);
});

test('writes title and description and completes the import', function () {
    InventoryExtractor::fake([['title' => 'Hammer & Meißel "Profi"', 'description' => 'Aufdruck "Raspberry Pi 4 Model B".']]);
    $import = Import::factory()->create();

    (new ExtractImport($import))->handle();

    expect($import->fresh())
        ->status->toBe(ImportStatus::Completed)
        ->title->toBe('Hammer & Meißel "Profi"')
        ->description->toBe('Aufdruck "Raspberry Pi 4 Model B".')
        ->error->toBeNull();
    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2);
});

test('marks the import running before prompting', function () {
    $import = Import::factory()->create();
    InventoryExtractor::fake(function () use ($import) {
        expect($import->fresh()->status)->toBe(ImportStatus::Running);

        return ['title' => 'T', 'description' => 'D'];
    });

    (new ExtractImport($import))->handle();
});

test('reports and rethrows when the agent throws, and failed() records the error', function () {
    Exceptions::fake();
    InventoryExtractor::fake(fn () => throw new RuntimeException('Connection refused'));
    $import = Import::factory()->completed()->create();
    $job = new ExtractImport($import);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Connection refused');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Connection refused');

    $job->failed(new RuntimeException('Connection refused'));

    expect($import->fresh())
        ->status->toBe(ImportStatus::Failed)
        ->error->toBe('Connection refused')
        ->title->toBeNull()
        ->description->toBeNull();
});

test('failed() without an exception stores a fallback message', function () {
    $import = Import::factory()->running()->create();

    (new ExtractImport($import))->failed(null);

    expect($import->fresh())->status->toBe(ImportStatus::Failed)->error->not->toBeEmpty();
});

test('an incomplete answer throws the incomplete-answer message', function () {
    InventoryExtractor::fake([['title' => 'Nur ein Titel']]);

    expect(fn () => (new ExtractImport(Import::factory()->create()))->handle())
        ->toThrow(RuntimeException::class, 'Das Modell hat keine vollständige Antwort geliefert.');
});

test('a non-string description is treated as incomplete', function () {
    InventoryExtractor::fake([['title' => 'X', 'description' => ['a', 'b']]]);
    $import = Import::factory()->create();

    expect(fn () => (new ExtractImport($import))->handle())->toThrow(RuntimeException::class);
    expect($import->fresh())->title->toBeNull()->description->toBeNull();
});

test('a missing photo file fails instead of prompting', function () {
    InventoryExtractor::fake();
    $import = Import::factory()->create(['photos' => ['imports/missing.jpg']]);

    expect(fn () => (new ExtractImport($import))->handle())->toThrow(Throwable::class);
    InventoryExtractor::assertNeverPrompted();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs/ExtractImportTest.php`
Expected: FAIL (constructor, `handle`, `failed` not implemented as specified)

- [ ] **Step 4: Implement `ExtractImport`**

`handle()`: update status `Running`; build `Image::fromStorage($path, 'local')` per photo (check `Storage::disk('local')->exists($path)` first and throw `RuntimeException("Foto {$path} wurde nicht gefunden.")` if missing); prompt the extractor; apply the string guard (`is_string` and non-blank for both fields, else `RuntimeException` with the incomplete-answer message); update `title`, `description`, `error => null`, status `Completed`. Wrap the body in `try { ... } catch (Throwable $e) { report($e); throw $e; }`. `failed(?Throwable $exception)`: update `status => Failed`, `error => $exception?->getMessage() ?: 'Der Auftrag wurde abgebrochen.'`. Do not implement `ShouldBeUnique`.

- [ ] **Step 5: Run the tests to verify they pass, then Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact tests/Feature/Jobs/ExtractImportTest.php && docker compose exec app ./vendor/bin/pint --dirty && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: 8 passed; clean

- [ ] **Step 6: Queue limits.** Add `DB_QUEUE_RETRY_AFTER=300` under `QUEUE_CONNECTION=database` in `.env.example` and as `DB_QUEUE_RETRY_AFTER: "300"` to the `app` service `environment` in `compose.yaml`. In both supervisor confs change the queue command's `--timeout=90` to `--timeout=210` and `stopwaitsecs=120` to `stopwaitsecs=230`.

- [ ] **Step 7: Rebuild and verify the running container**

Run: `docker compose build app && docker compose up -d app && docker compose exec app php artisan config:show queue.connections.database.retry_after && docker compose exec app supervisorctl status`
Expected: `retry_after ... 300`; three RUNNING programs

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/ExtractImport.php tests/Feature/Jobs .env.example compose.yaml docker/supervisor
git -c core.hooksPath=/dev/null commit -m "Add ExtractImport queue job and raise queue timeouts"
```

---

### Task 3: Import resource with the create page; remove the synchronous page

**Files:**
- Create (generated): `app/Filament/Resources/Imports/ImportResource.php`, `.../Imports/Pages/ListImports.php`, `.../Imports/Pages/CreateImport.php`, `.../Imports/Pages/ViewImport.php`, `.../Imports/Schemas/ImportForm.php`, `.../Imports/Schemas/ImportInfolist.php`, `.../Imports/Tables/ImportsTable.php` (if the generator picks other names, keep its names and use them in Tasks 4–5)
- Delete: `app/Filament/Pages/ExtractItem.php`, `resources/views/filament/pages/extract-item.blade.php`, `tests/Feature/Panel/ExtractItemPageTest.php` (deletion approved in the spec)
- Test: `tests/Feature/Panel/CreateImportTest.php`

**Interfaces:**
- Consumes: `Import`, `ImportStatus`, `ExtractImport::dispatch(Import $import)`.
- Produces: `ImportResource` (`$model = Import::class`, `$slug = 'importe'`, `$modelLabel = 'Import'`, `$pluralModelLabel = 'Importe'`, `$navigationLabel = 'Importe'`, `canEdit(Model $record): bool` returning `false`, pages `index`, `create`, `view`); `ImportResource::getUrl('view', ['record' => $import])`; `CreateImport` with `$title = 'Erfassen'`; `ImportForm::configure(Schema $schema): Schema` holding the `FileUpload::make('photos')` field.

- [ ] **Step 1: Generate the resource**

```bash
docker compose exec app php artisan make:filament-resource Import --view --no-interaction
```

List the generated files and note their names.

- [ ] **Step 2: Delete the synchronous page, its view and its test file** (`git rm` the three files).

- [ ] **Step 3: Write the failing tests** in `tests/Feature/Panel/CreateImportTest.php`

```php
use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\CreateImport;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the create page is reachable for verified users and titled Erfassen', function () {
    $this->get('/importe/create')->assertOk()->assertSee('Erfassen');
});

test('unverified users are redirected to the verification prompt', function () {
    auth()->logout();
    $this->actingAs(User::factory()->unverified()->create());

    $this->get('/importe/create')->assertRedirect('/email-verification/prompt');
});

test('guests are redirected to login', function () {
    auth()->logout();

    $this->get('/importe/create')->assertRedirect('/login');
});

test('submitting without images fails validation and dispatches nothing', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => []])
        ->call('create')
        ->assertHasFormErrors(['photos']);

    Queue::assertNothingPushed();
    expect(Import::count())->toBe(0);
});

test('rejects a non-image file', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('notes.jpg', 10, 'text/plain')]])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('rejects image formats the provider cannot read', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->create('photo.heic', 10, 'image/heic')]])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('rejects more than six images', function () {
    $photos = collect(range(1, 7))->map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"))->all();

    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => $photos])
        ->call('create')
        ->assertHasFormErrors(['photos']);
});

test('a valid upload stores the photos, creates a pending import, dispatches the job and redirects to the view page', function () {
    Livewire::test(CreateImport::class)
        ->fillForm(['photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')]])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(ImportResource::getUrl('view', ['record' => Import::first()]));

    $import = Import::sole();

    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->user_id)->toBe($this->user->id)
        ->and($import->photos)->toHaveCount(2)
        ->and($import->title)->toBeNull();

    foreach ($import->photos as $path) {
        expect($path)->toStartWith('imports/');
        Storage::disk('local')->assertExists($path);
    }

    Queue::assertPushed(ExtractImport::class, fn (ExtractImport $job) => $job->import->is($import));
    Queue::assertPushed(ExtractImport::class, 1);
});

test('the old synchronous page is gone', function () {
    $this->get('/erfassen')->assertNotFound();
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/CreateImportTest.php`
Expected: FAIL (form has no `photos` field, no dispatch, labels missing)

- [ ] **Step 5: Implement**

`ImportResource`: the static properties and `canEdit()` from the Interfaces block; `$navigationIcon = Heroicon::OutlinedPhoto`; `getPages()` with index/create/view only. `ImportForm::configure()`: the `FileUpload::make('photos')->label('Fotos')` with every option from Global Constraints plus `->disk('local')->directory('imports')->visibility('private')->reorderable()->panelLayout('grid')` (no `storeFiles(false)`). `CreateImport`: `$title = 'Erfassen'`; `mutateFormDataBeforeCreate()` sets `user_id => auth()->id()` and `status => ImportStatus::Pending`; `afterCreate()` calls `ExtractImport::dispatch($this->getRecord())`; `getCreatedNotificationTitle()` returns `'Import angelegt'`. Leave the generated infolist and table for Tasks 4–5. The redirect to the view page is Filament's default once `canView` is true and no edit page exists; do not override `getRedirectUrl()` unless the redirect test fails.

- [ ] **Step 6: Run the full suite, Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact && docker compose exec app ./vendor/bin/pint --dirty && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all passed (the deleted file's 12 tests are gone, 9 new ones pass); clean

- [ ] **Step 7: Commit**

```bash
git add -A app/Filament tests/Feature/Panel resources/views
git -c core.hooksPath=/dev/null commit -m "Replace Erfassen page with Import resource that queues extraction"
```

---

### Task 4: View page with polling and Retry

**Files:**
- Modify: `app/Filament/Resources/Imports/Schemas/ImportInfolist.php`, `app/Filament/Resources/Imports/Pages/ViewImport.php`
- Test: `tests/Feature/Panel/ViewImportTest.php`

**Interfaces:**
- Consumes: `Import`, `ImportStatus::isActive()`, `ExtractImport::dispatch()`, `ImportResource`.
- Produces: header action named `retry` on `ViewImport`.

- [ ] **Step 1: Write the failing tests** in `tests/Feature/Panel/ViewImportTest.php`

```php
use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Jobs\ExtractImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

test('a pending import shows its status and polls', function () {
    $import = Import::factory()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertOk()
        ->assertSee('Wartet')
        ->assertSeeHtml('wire:poll.5s')
        ->assertActionHidden('retry');
});

test('a running import polls', function () {
    $import = Import::factory()->running()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Läuft')
        ->assertSeeHtml('wire:poll.5s');
});

test('a completed import shows title and description and stops polling', function () {
    $import = Import::factory()->completed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Fertig')
        ->assertSee('Raspberry Pi 4')
        ->assertSee('Grüne Platine mit vier USB-Anschlüssen.')
        ->assertDontSeeHtml('wire:poll')
        ->assertActionHidden('retry');
});

test('a status change in the database shows up on the next refresh', function () {
    $import = Import::factory()->create();

    $page = Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->assertSee('Wartet');

    $import->update(['status' => ImportStatus::Completed, 'title' => 'Neuer Titel', 'description' => 'Neue Beschreibung']);

    $page->call('$refresh')->assertSee('Fertig')->assertSee('Neuer Titel')->assertDontSeeHtml('wire:poll');
});

test('a failed import shows the error with debug on and offers retry', function () {
    config(['app.debug' => true]);
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Fehlgeschlagen')
        ->assertSee('Connection refused')
        ->assertDontSeeHtml('wire:poll')
        ->assertActionVisible('retry');
});

test('a failed import hides the raw error with debug off', function () {
    config(['app.debug' => false]);
    $import = Import::factory()->failed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.')
        ->assertDontSee('Connection refused');
});

test('retry resets a failed import and dispatches the job again', function () {
    $import = Import::factory()->failed()->create(['title' => 'Alt', 'description' => 'Alt']);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('retry')
        ->assertNotified('Erneut gestartet')
        ->assertSee('Wartet')
        ->assertSeeHtml('wire:poll.5s');

    expect($import->fresh())
        ->status->toBe(ImportStatus::Pending)
        ->error->toBeNull()
        ->title->toBeNull()
        ->description->toBeNull();
    Queue::assertPushed(ExtractImport::class, fn (ExtractImport $job) => $job->import->is($import));
    Queue::assertPushed(ExtractImport::class, 1);
});

test('retry is hidden for running imports', function () {
    $import = Import::factory()->running()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->assertActionHidden('retry');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/ViewImportTest.php`
Expected: FAIL (no poll attribute, no `retry` action, generated infolist shows raw columns)

- [ ] **Step 3: Implement**

`ImportInfolist::configure()`: one `Section::make()` wrapping everything, with `->extraAttributes(fn (Import $record): array => $record->status->isActive() ? ['wire:poll.5s' => ''] : [])` (a full component poll; the schema `->poll()` method only re-renders that component partially and would not refresh the header actions). Inside: `ImageEntry::make('photos')->label('Fotos')->disk('local')->visibility('private')` (stacked, builder's sizing), `TextEntry::make('status')->label('Status')->badge()`, `TextEntry::make('title')->label('Titel')->visible(fn (Import $record) => filled($record->title))`, `TextEntry::make('description')->label('Beschreibung')->visible(...)`, `TextEntry::make('error')->label('Fehler')->color('danger')->visible(fn (Import $record) => $record->status === ImportStatus::Failed)->state(fn (Import $record) => app()->hasDebugModeEnabled() ? $record->error : 'Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.')`, `TextEntry::make('created_at')->label('Angelegt')->dateTime()`, `TextEntry::make('updated_at')->label('Aktualisiert')->dateTime()`.

`ViewImport::getHeaderActions()`: `Action::make('retry')->label('Erneut versuchen')->color('warning')->visible(fn (): bool => $this->getRecord()->status === ImportStatus::Failed)->action(function (): void { ... })` which calls `$this->getRecord()->update(['status' => ImportStatus::Pending, 'error' => null, 'title' => null, 'description' => null])` (updating the page's own record instance so the re-render shows "Wartet"), dispatches `ExtractImport::dispatch($this->getRecord())`, and sends `Notification::make()->title('Erneut gestartet')->success()->send()`.

- [ ] **Step 4: Run the tests to verify they pass, then the suite, Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact && docker compose exec app ./vendor/bin/pint --dirty && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all passed (8 new); clean

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Imports tests/Feature/Panel/ViewImportTest.php
git -c core.hooksPath=/dev/null commit -m "Show import status with polling and a retry action"
```

---

### Task 5: List page, docs

**Files:**
- Modify: `app/Filament/Resources/Imports/Tables/ImportsTable.php`, `app/Filament/Resources/Imports/Pages/ListImports.php`, `CLAUDE.md:39` (the "Inventory extraction" bullet), `README.md:44` (the "Erfassen" paragraph)
- Test: `tests/Feature/Panel/ListImportsTest.php`

**Interfaces:**
- Consumes: `ImportResource`, `Import` factory states.

- [ ] **Step 1: Write the failing tests** in `tests/Feature/Panel/ListImportsTest.php`

```php
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
});

test('the list is reachable and in the navigation as Importe', function () {
    $this->get('/importe')->assertOk()->assertSee('Importe');
});

test('imports are listed newest first with status and title', function () {
    $older = Import::factory()->completed()->create(['created_at' => now()->subDay()]);
    $newer = Import::factory()->failed()->create();

    Livewire::test(ListImports::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertSee('Raspberry Pi 4')
        ->assertSee('Fehlgeschlagen')
        ->assertSee('Fertig');
});

test('the list offers the Erfassen action', function () {
    Livewire::test(ListImports::class)->assertActionVisible('create');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/ListImportsTest.php`
Expected: FAIL (default table has no status/title columns in the right order; label text missing)

- [ ] **Step 3: Implement** `ImportsTable::configure()`: columns `ImageColumn::make('photos')->label('Foto')->disk('local')->visibility('private')->limit(1)`, `TextColumn::make('title')->label('Titel')->placeholder('—')`, `TextColumn::make('status')->label('Status')->badge()`, `TextColumn::make('created_at')->label('Angelegt')->dateTime()->sortable()`; `->defaultSort('created_at', 'desc')`; `->recordUrl(fn (Import $record) => ImportResource::getUrl('view', ['record' => $record]))`; `->poll('10s')` (builder's choice to keep or drop). `ListImports::getHeaderActions()`: `CreateAction::make()->label('Erfassen')`.

- [ ] **Step 4: Docs.** Replace the CLAUDE.md "Inventory extraction" bullet with one describing: resource `app/Filament/Resources/Imports/` at `/importe` (list, create "Erfassen", view), model `Import` + `ImportStatus`, photos on the private `local` disk under `imports/`, job `app/Jobs/ExtractImport.php` (1 try, 200 s), `DB_QUEUE_RETRY_AFTER=300` above the job timeout and the supervisord `--timeout=210`, the view page polling while pending/running, Retry on failed, error text shown only in debug, and the unchanged extractor/gemma4 bare-JSON note. Update the README paragraph to match (upload at `/importe/create`, result appears on the import page, queue must run).

- [ ] **Step 5: Run the suite, Pint and PHPStan**

Run: `docker compose exec app php artisan test --compact && docker compose exec app ./vendor/bin/pint --dirty && docker compose exec app ./vendor/bin/phpstan analyse`
Expected: all passed; clean

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Imports tests/Feature/Panel/ListImportsTest.php CLAUDE.md README.md
git -c core.hooksPath=/dev/null commit -m "List imports and document the asynchronous import flow"
```

---

## Verification (end-to-end, after Task 5)

1. `docker compose exec app php artisan migrate` (AUTO_MIGRATE also covers it on restart); `php artisan route:list --path=importe` shows index, create and view routes; `/erfassen` is 404.
2. With local `.env` pointing at Ollama (`INVENTORY_AI_PROVIDER=ollama`, `INVENTORY_AI_MODEL=gemma4:e4b-mlx`, `OLLAMA_URL=http://host.docker.internal:11434`): create an import from the CLI to exercise the real job: `php artisan tinker --execute='$i = App\Models\Import::factory()->create(["user_id" => App\Models\User::first()->id, "photos" => ["imports/test.jpg"]]); App\Jobs\ExtractImport::dispatch($i);'` after putting a real JPEG at `storage/app/private/imports/test.jpg`; watch `docker compose logs -f app` and `php artisan tinker --execute='App\Models\Import::latest()->first()->only(["status","title","error"]);'` go pending → running → completed with German text.
3. Stop Ollama and repeat: status ends `failed` with a connection error in `error`.
4. Browser acceptance (left to the user): upload at http://localhost:8000/importe/create, watch the badge change on the import page, use Retry on a failed one.
