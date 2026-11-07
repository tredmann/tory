<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Imports\ImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImports extends ListRecords
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome;

    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Erfassen'),
        ];
    }
}
