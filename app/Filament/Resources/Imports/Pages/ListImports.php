<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Widgets\OpenBulkImportsWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImports extends ListRecords
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome;

    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add item')),
            Action::make('captureMany')
                ->label(__('Add many'))
                ->color('gray')
                ->url(BulkImportResource::getUrl('create')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [OpenBulkImportsWidget::class];
    }
}
