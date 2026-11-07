<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Widgets\ItemSearchWidget;
use App\Filament\Widgets\LocationsWidget;
use App\Filament\Widgets\OpenBulkImportsWidget;
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
            Action::make('captureMany')
                ->label('Viele erfassen')
                ->color('gray')
                ->url(BulkImportResource::getUrl('create')),
        ];
    }

    /**
     * Search, then open (bulk) imports stacked in one column and locations side by side (one fills the row alone), then the recent items.
     */
    public function getWidgetsContentComponent(): Component
    {
        $imports = $this->getWidgetsSchemaComponents([OpenBulkImportsWidget::class, OpenImportsWidget::class]);
        $overview = [
            ...($imports === [] ? [] : [Grid::make(1)->schema($imports)]),
            ...$this->getWidgetsSchemaComponents([LocationsWidget::class]),
        ];

        return Grid::make(1)
            ->schema([
                ...$this->getWidgetsSchemaComponents([ItemSearchWidget::class]),
                Grid::make(['default' => 1, 'lg' => max(1, count($overview))])->schema($overview),
                ...$this->getWidgetsSchemaComponents([RecentItemsWidget::class]),
            ]);
    }
}
