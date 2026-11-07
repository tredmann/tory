<?php

namespace App\Filament\Resources\Spots\Pages;

use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Spots\SpotResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSpot extends EditRecord
{
    use StartsBreadcrumbsAtHome;

    protected static string $resource = SpotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
