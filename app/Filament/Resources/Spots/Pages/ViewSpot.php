<?php

namespace App\Filament\Resources\Spots\Pages;

use App\Filament\Actions\SpotLabelsAction;
use App\Filament\Resources\Spots\SpotResource;
use App\Models\Spot;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSpot extends ViewRecord
{
    protected static string $resource = SpotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SpotLabelsAction::make(),
            EditAction::make(),
            DeleteAction::make()
                ->before(function (DeleteAction $action, Spot $record): void {
                    if (! $record->items()->exists()) {
                        return;
                    }

                    Notification::make()
                        ->title('Der Lagerplatz enthält noch Gegenstände und kann nicht gelöscht werden.')
                        ->danger()
                        ->send();

                    $action->halt();
                }),
        ];
    }
}
