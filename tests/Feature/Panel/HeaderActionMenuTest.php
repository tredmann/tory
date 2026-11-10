<?php

use App\Filament\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Resources\BulkImports\Pages\ViewBulkImport;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Filament\Resources\Spots\Pages\ViewSpot;
use App\Models\BulkImport;
use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * The header in order: visible actions by name, then the menu as a list of its action names.
 *
 * @return list<string|list<string>>
 */
function headerActions(object $page): array
{
    return array_map(function ($action): string|array {
        if (! $action instanceof ActionGroup) {
            return $action->getName();
        }

        expect($action->getIcon())->toBe(Heroicon::EllipsisVertical);

        return array_keys($action->getFlatActions());
    }, $page->getCachedHeaderActions());
}

test('only creating actions and a page\'s main step stay out of the menu', function (Closure $open, array $expected) {
    expect(headerActions($open()->instance()))->toBe($expected);
})->with([
    'import' => [
        fn () => Livewire::test(ViewImport::class, ['record' => Import::factory()->completed()->create()->getRouteKey()]),
        ['retry', 'convert', ['regenerate', 'delete']],
    ],
    'bulk import' => [
        fn () => Livewire::test(ViewBulkImport::class, ['record' => BulkImport::factory()->ready()->create()->getRouteKey()]),
        ['confirm', ['delete']],
    ],
    'item' => [
        fn () => Livewire::test(ViewItem::class, ['record' => Item::factory()->create()->getRouteKey()]),
        [['regenerate', 'edit', 'delete']],
    ],
    'spot' => [
        fn () => Livewire::test(ViewSpot::class, ['record' => Spot::factory()->create()->getRouteKey()]),
        [['labels', 'edit', 'delete']],
    ],
    'ai providers' => [
        fn () => Livewire::test(ManageAiProviders::class),
        ['create', ['export', 'import']],
    ],
]);
