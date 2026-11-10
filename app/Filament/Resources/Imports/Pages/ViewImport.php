<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportStatus;
use App\Filament\Actions\RegenerateTextsAction;
use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Concerns\TitledByRecord;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Spots\Schemas\SpotSelect;
use App\Models\Import;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewImport extends ViewRecord
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome, TitledByRecord;

    protected static string $resource = ImportResource::class;

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry')
                ->label('Erneut versuchen')
                ->color('warning')
                ->visible(fn (): bool => $this->import()->status === ImportStatus::Failed)
                ->action(function (): void {
                    $this->import()->restart();

                    Notification::make()
                        ->title('Erneut gestartet')
                        ->success()
                        ->send();
                }),
            Action::make('convert')
                ->label('Übernehmen')
                ->visible(fn (): bool => $this->import()->isFinished() && ! $this->import()->isRegenerating())
                ->fillForm(fn (): array => [
                    'title' => $this->import()->title,
                    'description' => $this->import()->description,
                    'search_terms' => $this->import()->search_terms ?? [],
                    'spot_id' => $this->import()->spot_id
                        ?? auth()->user()?->last_spot_id,
                ])
                ->schema([
                    TextInput::make('title')
                        ->label('Titel')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Beschreibung'),
                    TagsInput::make('search_terms')
                        ->label('Suchbegriffe')
                        ->splitKeys(['Tab', ',']),
                    SpotSelect::make(),
                ])
                ->action(function (array $data): void {
                    $spotId = (int) $data['spot_id'];

                    $this->import()->convertToItem($data['title'], $data['description'] ?? null, $spotId, $data['search_terms'] ?? []);

                    auth()->user()?->forceFill(['last_spot_id' => $spotId])->save();

                    Notification::make()
                        ->title('Gegenstand angelegt')
                        ->success()
                        ->send();

                    $this->redirect(ImportResource::getUrl('index'));
                }),
            ActionGroup::make([
                RegenerateTextsAction::make(),
                DeleteAction::make()
                    ->label('Löschen')
                    ->visible(fn (): bool => $this->import()->isFinished())
                    ->successRedirectUrl(ImportResource::getUrl('index')),
            ])->color('gray'),
        ];
    }

    private function import(): Import
    {
        $import = $this->getRecord();
        assert($import instanceof Import);

        return $import;
    }
}
