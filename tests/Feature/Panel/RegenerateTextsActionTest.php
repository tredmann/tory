<?php

use App\Enums\ProviderFailureReason;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Jobs\RegenerateTexts;
use App\Models\Import;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

test('the action starts the job on an item', function () {
    $item = Item::factory()->create();

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])->callAction('regenerate');

    Queue::assertPushed(RegenerateTexts::class);
    expect($item->fresh()->isRegenerating())->toBeTrue();
});

test('the action starts the job on a completed import', function () {
    $import = Import::factory()->completed()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->callAction('regenerate');

    Queue::assertPushed(RegenerateTexts::class);
    expect($import->fresh()->isRegenerating())->toBeTrue();
});

test('right after starting, the page shows the running state and polls', function () {
    $import = Import::factory()->completed()->create();
    $item = Item::factory()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->callAction('regenerate')
        ->assertSee('Wird neu generiert …')
        ->assertSeeHtml('wire:poll.5s');
    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->callAction('regenerate')
        ->assertSee('Wird neu generiert …')
        ->assertSeeHtml('wire:poll.5s');
});

test('while regenerating, the import status itself says so', function () {
    $import = Import::factory()->completed()->create(['regenerating_at' => now()]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSeeInOrder(['Status', 'Wird neu generiert …', 'Titel'])
        ->assertDontSee('Fertig');
});

test('while regenerating, an item shows the state right below its photos', function () {
    $item = Item::factory()->create(['regenerating_at' => now(), 'description' => 'Gelb, mit Ladegerät']);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSeeInOrder(['Fotos', 'Wird neu generiert …', 'Gelb, mit Ladegerät']);
});

test('the action is hidden on pending, running and failed imports', function (string $state) {
    $import = Import::factory()->{$state}()->create();

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])->assertActionHidden('regenerate');
})->with(['pending', 'running', 'failed']);

test('a second start dispatches no second job', function () {
    $item = Item::factory()->create();

    expect($item->startRegeneration())->toBeTrue()
        ->and($item->startRegeneration())->toBeFalse()
        ->and($item->fresh()->isRegenerating())->toBeTrue();
});

test('a model without the attribute loaded is not regenerating', function () {
    expect((new Item)->isRegenerating())->toBeFalse();
});

test('while regenerating, regenerate, edit and convert are hidden', function () {
    $item = Item::factory()->create(['regenerating_at' => now()]);
    $import = Import::factory()->completed()->create(['regenerating_at' => now()]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertActionHidden('regenerate')
        ->assertActionHidden('edit');
    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertActionHidden('regenerate')
        ->assertActionHidden('convert');
});

test('while regenerating, the page shows the state and polls', function () {
    $item = Item::factory()->create(['regenerating_at' => now()]);
    $import = Import::factory()->completed()->create(['regenerating_at' => now()]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Wird neu generiert …')
        ->assertSeeHtml('wire:poll.5s');
    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Wird neu generiert …')
        ->assertSeeHtml('wire:poll.5s');
});

test('an idle item page does not poll', function () {
    $item = Item::factory()->create();

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertDontSee('Wird neu generiert …')
        ->assertDontSeeHtml('wire:poll');
});

test('the edit page redirects to the view page while regenerating', function () {
    $item = Item::factory()->create(['regenerating_at' => now()]);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertRedirect(ViewItem::getUrl(['record' => $item]));
});

test('saving on an edit page opened before a run does not overwrite the run', function () {
    $item = Item::factory()->create(['title' => 'Alt']);

    $page = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()]);
    $item->startRegeneration();

    $page->fillForm(['title' => 'Neu'])
        ->call('save')
        ->assertNotified()
        ->assertRedirect(ViewItem::getUrl(['record' => $item]));

    expect($item->fresh()->title)->toBe('Alt');
});

test('a failing dispatch leaves the record startable', function () {
    $item = Item::factory()->create();
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue down'));

    expect(fn () => Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])->callAction('regenerate'))
        ->toThrow(RuntimeException::class);

    expect($item->fresh()->isRegenerating())->toBeFalse();
});

test('a failure shows the reason with the provider link', function () {
    $this->actingAs(User::factory()->admin()->create());
    config(['app.debug' => false]);
    $item = Item::factory()->create([
        'regeneration_error' => 'geheim',
        'regeneration_failure_reason' => ProviderFailureReason::KeyRejected,
        'regeneration_failure_provider' => 'Claude schnell',
    ]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Claude schnell hat den API-Key abgelehnt. Provider prüfen')
        ->assertSeeHtml(AiProviderResource::getUrl('index'))
        ->assertDontSee('geheim');
});

test('a failure on an import shows the reason with the provider link', function () {
    $this->actingAs(User::factory()->admin()->create());
    config(['app.debug' => false]);
    $import = Import::factory()->completed()->create([
        'regeneration_error' => 'geheim',
        'regeneration_failure_reason' => ProviderFailureReason::KeyRejected,
        'regeneration_failure_provider' => 'Claude schnell',
    ]);

    Livewire::test(ViewImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('Claude schnell hat den API-Key abgelehnt. Provider prüfen')
        ->assertSeeHtml(AiProviderResource::getUrl('index'))
        ->assertDontSee('geheim');
});

test('a member sees the regeneration failure without the provider link', function () {
    config(['app.debug' => false]);
    $item = Item::factory()->create([
        'regeneration_failure_reason' => ProviderFailureReason::KeyRejected,
        'regeneration_failure_provider' => 'Claude schnell',
    ]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Claude schnell hat den API-Key abgelehnt.')
        ->assertDontSee('Provider prüfen')
        ->assertDontSeeHtml('/ai-providers');
});

test('an unclassified failure shows the general sentence', function () {
    config(['app.debug' => false]);
    $item = Item::factory()->create(['regeneration_error' => 'geheim']);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Neu generieren ist fehlgeschlagen. Die bisherigen Texte sind erhalten.')
        ->assertDontSee('geheim');
});

test('the raw error shows with debug on', function () {
    config(['app.debug' => true]);
    $item = Item::factory()->create(['regeneration_error' => 'geheim']);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])->assertSee('geheim');
});

test('the action is hidden without photos', function () {
    $item = Item::factory()->create(['photos' => []]);

    Livewire::test(ViewItem::class, ['record' => $item->getRouteKey()])->assertActionHidden('regenerate');
});
