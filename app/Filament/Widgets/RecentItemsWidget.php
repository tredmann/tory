<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Schemas\ItemGrid;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentItemsWidget extends TableWidget
{
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return ItemGrid::configure($table)
            ->query(fn (): Builder => Item::query()->latest()->limit(8))
            ->heading(__('Recently added'))
            ->description(fn (): string => trans_choice(':count item|:count items', Item::query()->count()))
            ->paginated(false)
            ->emptyStateHeading(__('Nothing added yet'))
            ->emptyStateIcon(Heroicon::OutlinedCamera)
            ->emptyStateActions([
                Action::make('capture')
                    ->label(__('Add item'))
                    ->url(ImportResource::getUrl('create')),
            ]);
    }
}
