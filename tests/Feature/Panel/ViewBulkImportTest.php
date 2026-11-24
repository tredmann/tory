<?php

use App\Enums\GroupMark;
use App\Enums\ImportStatus;
use App\Enums\ProviderFailureReason;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\BulkImports\Pages\ViewBulkImport;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\ExtractImport;
use App\Models\BulkImport;
use App\Models\Import;
use App\Models\Spot;
use App\Models\User;
use App\Photos\PhotoLinks;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * A ready bulk import with one card per entry of $cardSizes.
 *
 * @param  list<int>  $cardSizes
 * @param  list<string|null>  $marks
 * @param  array<string, mixed>  $attributes
 */
function readyBulkImport(array $cardSizes, array $marks = [], array $attributes = []): BulkImport
{
    $photos = [];
    $groups = [];

    foreach ($cardSizes as $card => $size) {
        $paths = [];

        for ($i = 0; $i < $size; $i++) {
            $path = 'imports/'.Str::uuid7().'.jpg';
            $paths[] = $path;
            $photos[] = ['path' => $path, 'taken_at' => null, 'name' => "IMG_{$card}_{$i}.jpg"];
        }

        $groups[] = ['id' => (string) Str::uuid7(), 'photos' => $paths, 'mark' => $marks[$card] ?? null];
    }

    $cuts = array_fill(0, max(0, count($photos) - 1), false);

    return BulkImport::factory()->ready($groups)->create([
        'photos' => $photos,
        'time_cuts' => $cuts,
        'cuts' => $cuts,
        ...$attributes,
    ]);
}

test('a grouping bulk import shows the waiting state and polls', function (): void {
    $bulkImport = BulkImport::factory()->grouping()->create();

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertOk()
        ->assertSee('Wird gruppiert …')
        ->assertSeeHtml('wire:poll.5s')
        ->assertDontSee('Gruppe 1')
        ->assertActionHidden('confirm')
        ->assertActionVisible('delete');
});

test('a ready bulk import shows its cards with counts and marks', function (): void {
    $spot = Spot::factory()->create(['name' => 'Werkbank']);
    $bulkImport = readyBulkImport([3, 2], [GroupMark::Merged->value, null], ['spot_id' => $spot->id]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSee('Bulkimport')
        ->assertSee($spot->label)
        ->assertSeeInOrder(['Gruppe 1 · 3 Fotos', 'KI: zusammengelegt', 'Mit nächster zusammenlegen', 'Gruppe 2 · 2 Fotos'])
        ->assertSeeHtml('<span aria-hidden="true">↓</span>')
        ->assertSeeHtml('wire:sort:group="bulk-photos"')
        ->assertSeeHtml('wire:sort:item="'.$bulkImport->groups[0]['photos'][0].'"')
        ->assertSeeHtml('wire:sort:group-id="'.$bulkImport->groups[1]['id'].'"')
        ->assertSeeHtml("wire:click=\"mergeWithNext('{$bulkImport->groups[0]['id']}', '{$bulkImport->groups[1]['id']}')\"")
        ->assertDontSeeHtml('wire:poll')
        ->assertActionVisible('confirm')
        ->assertActionEnabled('confirm')
        ->assertActionHasLabel('confirm', '2 Importe anlegen');
});

test('the review page shows the thumbnail of a group photo', function (): void {
    $bulkImport = readyBulkImport([1]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSeeHtml(PhotoLinks::thumbnail($bulkImport->groups[0]['photos'][0]));
});

test('the review page keeps no form data in its state', function (): void {
    $bulkImport = readyBulkImport([2, 1]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSet('data', []);
});

test('the last card offers no merge and discarded photos do not count', function (): void {
    $bulkImport = readyBulkImport([2]);
    $bulkImport->update(['discarded' => [$bulkImport->groups[0]['photos'][1]]]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSee('Gruppe 1 · 1 Foto')
        ->assertSee('rückgängig')
        ->assertSeeHtml('aria-label="Foto 2 wiederherstellen"')
        ->assertDontSee('Mit nächster zusammenlegen')
        ->assertActionHasLabel('confirm', '1 Import anlegen');
});

test('the provider reason links to Eigene KI', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $bulkImport = readyBulkImport([2], attributes: [
        'grouping_incomplete' => true,
        'failure_reason' => ProviderFailureReason::KeyRejected,
        'failure_provider' => 'Claude',
    ]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSee($bulkImport->failureMessage())
        ->assertSee('Provider prüfen')
        ->assertSeeHtml('href="'.AiProviderResource::getUrl('index').'"')
        ->assertDontSee('Die KI-Prüfung ist nicht vollständig gelaufen.');
});

test('a member sees the provider reason without the link', function (): void {
    $bulkImport = readyBulkImport([2], attributes: [
        'grouping_incomplete' => true,
        'failure_reason' => ProviderFailureReason::KeyRejected,
        'failure_provider' => 'Claude',
    ]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSee($bulkImport->failureMessage())
        ->assertDontSee('Provider prüfen')
        ->assertDontSeeHtml('/ai-providers');
});

test('an incomplete grouping shows the general note', function (): void {
    $bulkImport = readyBulkImport([2], attributes: ['grouping_incomplete' => true]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSee('Die KI-Prüfung ist nicht vollständig gelaufen. Ein Teil der Gruppen beruht nur auf der Aufnahmezeit.')
        ->assertDontSee('Provider prüfen');
});

test('a complete grouping shows no note', function (): void {
    $bulkImport = readyBulkImport([2]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertDontSee('Die KI-Prüfung ist nicht vollständig gelaufen.')
        ->assertDontSee('Provider prüfen');
});

test('moving, splitting, merging and discarding call through', function (): void {
    $bulkImport = readyBulkImport([2, 2]);
    [$first, $second] = $bulkImport->groups;
    $page = Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()]);

    $page->call('movePhoto', $first['photos'][0], $second['id'], 1);
    expect($bulkImport->fresh()->groups[1]['photos'])->toBe([$second['photos'][0], $first['photos'][0], $second['photos'][1]]);

    $page->call('splitBefore', $first['photos'][0]);
    expect(array_map(fn (array $group): array => $group['photos'], $bulkImport->fresh()->groups))
        ->toBe([[$first['photos'][1]], [$second['photos'][0]], [$first['photos'][0], $second['photos'][1]]]);

    $page->call('mergeWithNext', $bulkImport->fresh()->groups[1]['id'], $bulkImport->fresh()->groups[2]['id']);
    expect($bulkImport->fresh()->groups[1]['photos'])->toBe([$second['photos'][0], $first['photos'][0], $second['photos'][1]]);

    $page->call('discard', $first['photos'][1]);
    expect($bulkImport->fresh()->discarded)->toBe([$first['photos'][1]]);

    $page->call('restore', $first['photos'][1])
        ->assertSee('Gruppe 2 · 3 Fotos');
    expect($bulkImport->fresh()->discarded)->toBe([]);
});

test('a stale edit shows the notice and the current cards', function (): void {
    $bulkImport = readyBulkImport([2, 2]);
    $page = Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSee('Gruppe 2 · 2 Fotos');
    [$upper, $lower] = $bulkImport->groups;
    $bulkImport->mergeWithNext($upper['id'], $lower['id']);
    $groups = $bulkImport->fresh()->groups;

    $page->call('mergeWithNext', $upper['id'], $lower['id'])
        ->assertNotified('Die Gruppen wurden inzwischen geändert. Die Seite zeigt jetzt den aktuellen Stand.')
        ->assertSee('Gruppe 1 · 4 Fotos')
        ->assertDontSee('Gruppe 2');

    expect($bulkImport->fresh()->groups)->toBe($groups);
});

test('confirm creates the imports and redirects to the import list', function (): void {
    $bulkImport = readyBulkImport([2, 1]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->callAction('confirm')
        ->assertNotified('2 Importe angelegt')
        ->assertRedirect(ImportResource::getUrl('index'));

    expect(Import::query()->count())->toBe(2)
        ->and(Import::query()->pluck('status')->all())->each->toBe(ImportStatus::Pending)
        ->and(BulkImport::query()->count())->toBe(0)
        ->and($this->user->fresh()->last_spot_id)->toBe($bulkImport->spot_id);
    Queue::assertPushed(ExtractImport::class, 2);
});

test('confirm is disabled over twenty photos in a card', function (): void {
    $bulkImport = readyBulkImport([21, 1]);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSee('Höchstens 20 Fotos pro Import. Bitte aufteilen.')
        ->assertActionDisabled('confirm');
});

test('delete removes the bulk import and its files', function (): void {
    $bulkImport = readyBulkImport([2]);
    $paths = $bulkImport->allPaths();
    Storage::disk('local')->assertExists($paths);

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(ImportResource::getUrl('index'));

    expect(BulkImport::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($paths);
});

test('an unknown record answers 404', function (): void {
    $this->get(BulkImportResource::getUrl('view', ['record' => Str::uuid7()]))->assertNotFound();
});

test('a drop into another card moves the photo there', function (): void {
    $bulkImport = readyBulkImport([2, 1]);
    [$first, $second] = $bulkImport->groups;

    Livewire::test(ViewBulkImport::class, ['record' => $bulkImport->getRouteKey()])
        ->assertSeeHtml('wire:sort="sortPhoto"')
        ->call('sortPhoto', $first['photos'][1], 0, $second['id']);

    expect($bulkImport->fresh()->groups[1]['photos'])->toBe([$first['photos'][1], $second['photos'][0]]);
});
