<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\ExtractImport;
use App\Models\Import;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImport extends ViewRecord
{
    protected static string $resource = ImportResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Erneut versuchen')
                ->color('warning')
                ->visible(fn (): bool => $this->import()->status === ImportStatus::Failed)
                ->action(function (): void {
                    $import = $this->import();

                    $import->update([
                        'status' => ImportStatus::Pending,
                        'error' => null,
                        'title' => null,
                        'description' => null,
                    ]);

                    ExtractImport::dispatch($import);

                    Notification::make()
                        ->title('Erneut gestartet')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function import(): Import
    {
        $import = $this->getRecord();
        assert($import instanceof Import);

        return $import;
    }
}
