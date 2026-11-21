<?php

use App\Enums\Language;
use App\Filament\Pages\HouseholdSettingsPage;
use App\Models\HouseholdSettings;
use App\Models\Item;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

test('an administrator finds the household page under Einstellungen', function (): void {
    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk();

    $settings = collect(Filament::getNavigation())
        ->first(fn (NavigationGroup $group): bool => $group->getLabel() === 'Einstellungen');

    expect(collect($settings->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())->toContain('Haushalt');
});

test('the page offers the content language, preset to Deutsch', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(HouseholdSettingsPage::class)
        ->assertSee('Inhaltssprache')
        ->assertSee('Gilt nur für neue Erfassungen und „Neu generieren“. Bestehende Gegenstände bleiben, wie sie sind.')
        ->assertFormSet(['content_language' => 'de']);
});

test('a plain user gets 403 and no navigation entry', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/household')->assertForbidden();

    $labels = collect(Filament::getNavigation())->flatMap(fn (NavigationGroup $group) => collect($group->getItems())->map(fn (NavigationItem $item): string => $item->getLabel()));
    expect($labels->all())->not->toContain('Haushalt');
});

test('saving changes the content language and leaves items and the queue alone', function (): void {
    $item = Item::factory()->create(['title' => 'Gartenschlauch', 'description' => 'Grün.', 'search_terms' => ['Schlauch']]);
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(HouseholdSettingsPage::class)
        ->fillForm(['content_language' => 'en'])
        ->call('save')
        ->assertNotified();

    expect(HouseholdSettings::current()->content_language)->toBe(Language::English);
    Queue::assertNothingPushed();

    $item->refresh();
    expect($item->title)->toBe('Gartenschlauch')->and($item->description)->toBe('Grün.')->and($item->search_terms)->toBe(['Schlauch']);
});
