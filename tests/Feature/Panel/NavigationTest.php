<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

test('the sidebar groups the pages under Inventar and Einstellungen', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk();

    $groups = collect(Filament::getNavigation())->mapWithKeys(fn (NavigationGroup $group): array => [
        (string) $group->getLabel() => collect($group->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all(),
    ]);

    expect($groups->all())->toBe([
        '' => ['Übersicht'],
        'Inventar' => ['Gegenstände', 'Lagerplätze', 'Lagerorte', 'Importe'],
        'Einstellungen' => ['Tory in Claude', 'Eigene KI', 'Nutzer'],
    ]);
});

test('the navigation groups cannot be collapsed', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk();

    $labelledGroups = collect(Filament::getNavigation())->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()));

    expect($labelledGroups)->toHaveCount(2)
        ->each(fn ($group) => $group->isCollapsible()->toBeFalse());
});
