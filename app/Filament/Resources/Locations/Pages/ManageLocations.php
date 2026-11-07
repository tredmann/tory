<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Locations\LocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLocations extends ManageRecords
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome;

    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Lagerort anlegen')
                ->createAnother(false),
        ];
    }
}
