<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Widgets\ItemSearchWidget;
use App\Filament\Widgets\LocationsWidget;
use App\Filament\Widgets\OnboardingWidget;
use App\Filament\Widgets\OpenBulkImportsWidget;
use App\Filament\Widgets\OpenImportsWidget;
use App\Filament\Widgets\RecentItemsWidget;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string|Htmlable
    {
        return __('Overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('Overview');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('capture')
                ->label(__('Add item'))
                ->url(ImportResource::getUrl('create')),
            Action::make('captureMany')
                ->label(__('Add many'))
                ->color('gray')
                ->url(BulkImportResource::getUrl('create')),
        ];
    }

    /**
     * The onboarding for administrators, then search, then open (bulk) imports stacked in one column and locations side by side (one fills the row alone), then the recent items.
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
                ...$this->getWidgetsSchemaComponents([OnboardingWidget::class]),
                ...$this->getWidgetsSchemaComponents([ItemSearchWidget::class]),
                Grid::make(['default' => 1, 'lg' => max(1, count($overview))])->schema($overview),
                ...$this->getWidgetsSchemaComponents([RecentItemsWidget::class]),
            ]);
    }
}
