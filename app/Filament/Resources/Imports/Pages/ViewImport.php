<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\StorageLocations\Schemas\StorageLocationSelect;
use App\Jobs\ExtractImport;
use App\Models\Import;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            Action::make('convert')
                ->label('Übernehmen')
                ->visible(fn (): bool => $this->isFinished())
                ->fillForm(fn (): array => [
                    'title' => $this->import()->title,
                    'description' => $this->import()->description,
                    'storage_location_id' => $this->import()->storage_location_id
                        ?? auth()->user()?->last_storage_location_id,
                ])
                ->schema([
                    TextInput::make('title')
                        ->label('Titel')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Beschreibung'),
                    StorageLocationSelect::make(),
                ])
                ->action(function (array $data): void {
                    $this->import()->convertToItem($data['title'], $data['description'] ?? null, (int) $data['storage_location_id']);

                    Notification::make()
                        ->title('Gegenstand angelegt')
                        ->success()
                        ->send();

                    $this->redirect(ImportResource::getUrl('index'));
                }),
            DeleteAction::make()
                ->label('Löschen')
                ->visible(fn (): bool => $this->isFinished())
                ->successRedirectUrl(ImportResource::getUrl('index')),
        ];
    }

    private function isFinished(): bool
    {
        return in_array($this->import()->status, [ImportStatus::Completed, ImportStatus::Failed], true);
    }

    private function import(): Import
    {
        $import = $this->getRecord();
        assert($import instanceof Import);

        return $import;
    }
}
