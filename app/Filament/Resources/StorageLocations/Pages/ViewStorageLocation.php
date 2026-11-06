<?php

namespace App\Filament\Resources\StorageLocations\Pages;

use App\Filament\Resources\StorageLocations\StorageLocationResource;
use App\Models\StorageLocation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStorageLocation extends ViewRecord
{
    protected static string $resource = StorageLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->before(function (DeleteAction $action, StorageLocation $record): void {
                    if (! $record->items()->exists()) {
                        return;
                    }

                    Notification::make()
                        ->title('Der Lagerort enthält noch Gegenstände und kann nicht gelöscht werden.')
                        ->danger()
                        ->send();

                    $action->halt();
                }),
        ];
    }
}
