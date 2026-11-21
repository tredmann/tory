<?php

use App\Enums\Language;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

test('the sidebar groups the pages under Inventar and Einstellungen', function (): void {
    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk();

    $groups = collect(Filament::getNavigation())->mapWithKeys(fn (NavigationGroup $group): array => [
        (string) $group->getLabel() => collect($group->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all(),
    ]);

    expect($groups->all())->toBe([
        '' => ['Übersicht'],
        'Inventar' => ['Gegenstände', 'Lagerplätze', 'Lagerorte', 'Importe'],
        'Einstellungen' => ['Tory in Claude', 'Eigene KI', 'Nutzer', 'Haushalt'],
    ]);
});

test('the navigation groups cannot be collapsed', function (): void {
    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk();

    $labelledGroups = collect(Filament::getNavigation())->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()));

    expect($labelledGroups)->toHaveCount(2)
        ->each(fn ($group) => $group->isCollapsible()->toBeFalse());
});

test('an English user sees each navigation group once, in English', function (): void {
    $user = User::factory()->admin()->create();
    $user->forceFill(['locale' => Language::English])->save();

    $response = $this->actingAs($user)->withHeader('Accept-Language', 'en')->get('/')->assertOk();

    expect(substr_count($response->getContent(), 'fi-sidebar-group-label'))->toBe(2);
    $response->assertSeeText('Inventory')->assertSeeText('Settings')->assertDontSeeText('Inventar')->assertDontSeeText('Einstellungen');
    expect(substr_count(strip_tags($response->getContent()), 'Inventory'))->toBe(1)
        ->and(substr_count(strip_tags($response->getContent()), 'Settings'))->toBe(1);

    $labelled = collect(Filament::getNavigation())->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()));

    expect($labelled)->toHaveCount(2)
        ->each(fn ($group) => $group->isCollapsible()->toBeFalse());
});
