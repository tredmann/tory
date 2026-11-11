<?php

use App\Filament\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('every row action sits in the menu', function (string $page, array $expected) {
    $actions = Livewire::test($page)->instance()->getTable()->getRecordActions();

    expect($actions)->toHaveCount(1)
        ->and($actions[0])->toBeInstanceOf(ActionGroup::class)
        ->and($actions[0]->getIcon())->toBe(Heroicon::EllipsisVertical)
        ->and(array_keys($actions[0]->getFlatActions()))->toBe($expected);
})->with([
    'locations' => [ManageLocations::class, ['edit', 'delete']],
    'users' => [ManageUsers::class, ['edit', 'issueInvite', 'lock', 'unlock']],
    'ai providers' => [ManageAiProviders::class, ['activate', 'activateForSearch', 'test', 'edit', 'delete']],
]);
