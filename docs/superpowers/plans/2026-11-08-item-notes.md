# Item Notes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Items get an optional, user-written note ("Bemerkung") that is readable in panel, REST API and MCP and writable in panel and REST API.

**Architecture:** One nullable `text` column `items.notes`. The AI paths (`RegenerateTexts`, embedding) never touch it. The panel form/infolist, the API requests/resource and `FindItemsTool` each pass it through.

**Tech Stack:** Laravel 13, Filament 5, Pest on Postgres, laravel/mcp.

**Spec:** `docs/superpowers/specs/2026-11-08-item-notes-design.md`

## Global Constraints

- Column name `notes`; German label "Bemerkung"; placeholder "—" when empty.
- `notes` stays out of the dirty lists in `Item::booted()` and out of `ItemEmbedder::textFor()`: changing it never dispatches `EmbedItem`.
- No note on imports, bulk imports or the convert flow; no MCP write tool.
- All commands run in Docker: `docker compose exec app …`. After the migration also run `docker compose exec app php artisan migrate` on the dev database.
- Before each commit: `docker compose exec app ./vendor/bin/pint --dirty --format agent`. Before the last commit also `docker compose exec app ./vendor/bin/phpstan analyse`.
- Commit messages end with `(TOR-15)` and the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Clearing the note in the panel edit form stores `null`, not `""` (test in Task 2).
2. A PATCH without `notes` keeps the existing note (test in Task 3).
3. `""` sent to the API clears the note (test in Task 3).
4. A note with line breaks keeps each MCP text result on one line (test in Task 4).
5. A note longer than 200 characters is shortened in `find-items` (test in Task 4).

---

### Task 1: Column, model and glossary

**Files:**
- Create: migration via `docker compose exec app php artisan make:migration add_notes_to_items_table --table=items --no-interaction`
- Modify: `app/Models/Item.php` (`$fillable`, `@property` docblock), `CONTEXT.md`
- Test: `tests/Feature/Models/ItemTest.php`, `tests/Feature/Jobs/RegenerateTextsTest.php`

**Interfaces:**
- Produces: `Item::$notes` (`?string`), fillable.

- [ ] **Step 1: Write the failing tests**

In `ItemTest.php` (follow its existing setup for `Queue::fake()`):

```php
test('changing the note does not recompute the embedding', function () {
    $item = Item::factory()->create();
    Queue::fake();

    $item->update(['notes' => 'Netzteil fehlt']);

    expect($item->fresh()->notes)->toBe('Netzteil fehlt');
    Queue::assertNotPushed(EmbedItem::class);
});
```

In `RegenerateTextsTest.php`:

```php
test('keeps the note of an item', function () {
    Queue::fake();
    InventoryExtractor::fake([['title' => 'Neu', 'description' => 'D', 'search_terms' => []]]);
    $item = ($this->makeRecord)('item');
    $item->update(['notes' => 'Dongle fehlt']);

    (new RegenerateTexts($item))->handle();

    expect($item->fresh())->title->toBe('Neu')->notes->toBe('Dongle fehlt');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Models/ItemTest.php tests/Feature/Jobs/RegenerateTextsTest.php --filter=note`
Expected: FAIL (column `notes` does not exist).

- [ ] **Step 3: Implement**

Migration: `$table->text('notes')->nullable()->after('description');`, `down()` drops it. Add `'notes'` to `Item::$fillable` after `'description'`; add `@property string|null $notes` to the docblock. In `CONTEXT.md` under "Things", after **Search term**, add:

```markdown
**Note** (Bemerkung):
Free text the user writes on an item, such as "ohne Netzteil" or "Dongle fehlt". The AI never writes or changes it, and search does not use it.
_Avoid_: Comment, Kommentar, remark
```

- [ ] **Step 4: Run the tests and verify they pass**

Same command. Expected: PASS. Then `docker compose exec app php artisan migrate`.

- [ ] **Step 5: Commit** — "Add a note column to items (TOR-15)"

---

### Task 2: Panel

**Files:**
- Modify: `app/Filament/Resources/Items/Schemas/ItemForm.php`, `app/Filament/Resources/Items/Schemas/ItemInfolist.php`
- Test: `tests/Feature/Panel/ItemResourceTest.php`

**Interfaces:**
- Consumes: `Item::$notes` (Task 1).

- [ ] **Step 1: Write the failing tests**

```php
test('the view page shows the note', function () {
    $item = Item::factory()->create(['notes' => 'Netzteil fehlt']);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Bemerkung')
        ->assertSee('Netzteil fehlt');
});

test('the edit page changes and clears the note', function () {
    $item = Item::factory()->create();

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['notes' => 'Dongle fehlt'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($item->fresh()->notes)->toBe('Dongle fehlt');

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['notes' => ''])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($item->fresh()->notes)->toBeNull();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Panel/ItemResourceTest.php --filter=note`
Expected: FAIL.

- [ ] **Step 3: Implement**

`ItemForm`: `Textarea::make('notes')->label('Bemerkung')->rows(3)` after `description`. If `""` is stored instead of `null`, add `->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null)`. `ItemInfolist`: `TextEntry::make('notes')->label('Bemerkung')->placeholder('—')->extraAttributes(['style' => 'white-space: pre-line'])` after `description`.

- [ ] **Step 4: Run the tests and verify they pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit** — "Show and edit the note in the panel (TOR-15)"

---

### Task 3: REST API

**Files:**
- Modify: `app/Http/Requests/Api/V1/StoreItemRequest.php`, `app/Http/Requests/Api/V1/UpdateItemRequest.php`, `app/Http/Resources/Api/V1/ItemResource.php`, `app/Http/Controllers/Api/V1/ItemController.php` (`store()`), `docs/api-handover.md`
- Test: `tests/Feature/Api/ItemsTest.php`

**Interfaces:**
- Consumes: `Item::$notes` (Task 1).
- Produces: JSON field `notes` (string or `null`) after `description` in every item response.

- [ ] **Step 1: Write the failing tests**

- Extend `creates an item with photos and texts without extraction`: send `'notes' => 'Ohne Netzteil'`, assert `->assertJsonPath('data.notes', 'Ohne Netzteil')`.
- Extend `creates an item without description and search terms`: `->assertJsonPath('data.notes', null)`.
- Extend `updates only the sent fields`: create with `'notes' => 'Alt'`, assert `$item->notes` is still `'Alt'`.
- New:

```php
it('changes and clears the note', function () {
    $item = Item::factory()->create();

    $this->patchJson("/api/v1/items/{$item->id}", ['notes' => 'Dongle fehlt'])
        ->assertOk()
        ->assertJsonPath('data.notes', 'Dongle fehlt');

    $this->patchJson("/api/v1/items/{$item->id}", ['notes' => ''])
        ->assertOk()
        ->assertJsonPath('data.notes', null);
    expect($item->fresh()->notes)->toBeNull();
});
```

- Add a dataset row to `rejects an invalid new item` for `notes` as an array (`fn (array $p) => [...$p, 'notes' => ['x']]`, field `notes`), following the dataset's existing shape.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/Api/ItemsTest.php`
Expected: the new and extended tests FAIL.

- [ ] **Step 3: Implement**

Rules: Store `'notes' => ['nullable', 'string']`, Update `'notes' => ['sometimes', 'nullable', 'string']`, each after `description` with a PHPDoc `What the user noted about the item, never written by the AI.` and `@example Ohne Netzteil`. `ItemController::store()` passes `'notes' => $request->validated('notes')`. `ItemResource` adds `'notes' => $this->notes` after `description`. `docs/api-handover.md`: add notes to the Item glossary line, the item JSON example (line ~103) and the note under it ("`description` and `notes` may be null").

- [ ] **Step 4: Run the tests and verify they pass**

Run the same file plus `tests/Feature/Api/DocsTest.php`. Expected: PASS.

- [ ] **Step 5: Commit** — "Read and write the note in the API (TOR-15)"

---

### Task 4: MCP find-items

**Files:**
- Modify: `app/Mcp/Tools/FindItemsTool.php`
- Test: `tests/Feature/Mcp/FindItemsToolTest.php`

**Interfaces:**
- Consumes: `Item::$notes` (Task 1).

- [ ] **Step 1: Write the failing tests**

```php
it('shows the note on one line and shortened', function () {
    Item::factory()->create(['title' => 'Beamer', 'notes' => "Fernbedienung fehlt\nKabel im Keller"]);
    Item::factory()->create(['title' => 'Router', 'notes' => str_repeat('a', 300)]);
    Item::factory()->create(['title' => 'Monitor']);

    $response = ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Beamer']);

    $response->assertSee('Beamer')
        ->assertSee('– Bemerkung: Fernbedienung fehlt Kabel im Keller')
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('items.0.notes', "Fernbedienung fehlt\nKabel im Keller")->etc());

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Router'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('items.0.notes', fn (string $notes) => mb_strlen($notes) <= 203)->etc());

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Monitor'])
        ->assertDontSee('Bemerkung')
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('items.0.notes', null)->etc());
});
```

Adjust to what the MCP test response supports if `assertDontSee` is missing; the three cases (line breaks flattened in the text, shortened in structured content, `null` and no suffix without a note) must each be checked.

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/Mcp/FindItemsToolTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

Structured item gets `'notes' => $item->notes === null ? null : Str::limit($item->notes, 200)` after `description`. The text line appends `' – Bemerkung: '.Str::squish($item['notes'])` when `notes` is not `null`. Line breaks stay in the structured content.

- [ ] **Step 4: Run the tests and verify they pass**

Same command. Expected: PASS. Then run the full suite: `docker compose exec app php artisan test --compact`, Pint and PHPStan.

- [ ] **Step 5: Commit** — "Show the note in find-items (TOR-15)"
