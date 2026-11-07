<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Widgets\ItemSearchWidget;
use App\Filament\Widgets\LocationsWidget;
use App\Filament\Widgets\OpenImportsWidget;
use App\Filament\Widgets\RecentItemsWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Übersicht';

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('capture')
                ->label('Erfassen')
                ->url(ImportResource::getUrl('create')),
        ];
    }

    /**
     * Search, then open imports and locations side by side (one fills the row alone), then the recent items.
     */
    public function getWidgetsContentComponent(): Component
    {
        $overview = $this->getWidgetsSchemaComponents([OpenImportsWidget::class, LocationsWidget::class]);

        return Grid::make(1)
            ->schema([
                ...$this->getWidgetsSchemaComponents([ItemSearchWidget::class]),
                Grid::make(['default' => 1, 'lg' => max(1, count($overview))])->schema($overview),
                ...$this->getWidgetsSchemaComponents([RecentItemsWidget::class]),
            ]);
    }
}
