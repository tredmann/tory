<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Items\ItemResource;
use Filament\Resources\Pages\ListRecords;

class ListItems extends ListRecords
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome;

    protected static string $resource = ItemResource::class;
}
