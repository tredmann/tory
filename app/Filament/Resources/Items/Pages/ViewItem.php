<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Actions\RegenerateTextsAction;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewItem extends ViewRecord
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RegenerateTextsAction::make(),
            EditAction::make()
                ->visible(fn (Item $record): bool => ! $record->isRegenerating()),
            DeleteAction::make(),
        ];
    }
}
