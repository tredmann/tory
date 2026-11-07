<?php

namespace App\Filament\Resources\Spots\Pages;

use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Spots\SpotResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSpot extends CreateRecord
{
    use StartsBreadcrumbsAtHome;

    protected static string $resource = SpotResource::class;
}
