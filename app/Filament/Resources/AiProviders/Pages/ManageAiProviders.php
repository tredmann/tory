<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Ai\ProviderTransfer;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageAiProviders extends ManageRecords
{
    protected static ?string $title = 'Eigene KI';

    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Exportieren')
                ->color('gray')
                ->disabled(fn (): bool => ! AiProvider::query()->exists())
                ->requiresConfirmation()
                ->modalHeading('Provider exportieren')
                ->modalDescription('Die Datei enthält die API-Keys im Klartext. Bewahre sie sicher auf und lösche sie nach dem Import.')
                ->modalSubmitActionLabel('Exportieren')
                ->action(fn (): StreamedResponse => $this->downloadExport()),
            Action::make('import')
                ->label('Importieren')
                ->color('gray')
                ->modalHeading('Provider importieren')
                ->modalDescription('Provider, deren UUID hier schon vorhanden ist, werden übersprungen. Neue Provider sind zunächst inaktiv.')
                ->modalSubmitActionLabel('Importieren')
                ->schema([
                    FileUpload::make('file')
                        ->label('Datei')
                        ->storeFiles(false)
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->maxSize(1024)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->importFile($data['file'])),
            CreateAction::make()
                ->label('Provider anlegen')
                ->createAnother(false),
        ];
    }

    private function downloadExport(): StreamedResponse
    {
        ['document' => $document, 'omitted' => $omitted] = app(ProviderTransfer::class)->export();
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($omitted > 0) {
            Notification::make()
                ->title($omitted === 1
                    ? '1 Provider mit unlesbarem Key wurde nicht exportiert.'
                    : "{$omitted} Provider mit unlesbarem Key wurden nicht exportiert.")
                ->warning()
                ->send();
        }

        return response()->streamDownload(
            function () use ($json): void {
                echo $json;
            },
            'tory-ai-providers-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * The upload holds keys in plain text, so it is removed whatever the outcome.
     */
    private function importFile(TemporaryUploadedFile $file): void
    {
        try {
            $result = app(ProviderTransfer::class)->import((string) $file->get());

            Notification::make()
                ->title("{$result['created']} angelegt, {$result['skipped']} übersprungen, {$result['renamed']} umbenannt")
                ->success()
                ->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Import fehlgeschlagen')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $file->delete();
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return AiProvider::active() === null
            ? 'Aktuell gilt die Serverkonfiguration (.env).'
            : null;
    }
}
