<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Ai\ProviderTransfer;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Models\AiProvider;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageAiProviders extends ManageRecords
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome;

    protected static string $resource = AiProviderResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('AI providers');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Create provider'))
                ->createAnother(false),
            ActionGroup::make([
                Action::make('export')
                    ->label(__('Export'))
                    ->color('gray')
                    ->groupedIcon(Heroicon::ArrowDownTray)
                    ->authorize('create')
                    ->disabled(fn (): bool => ! AiProvider::query()->exists())
                    ->requiresConfirmation()
                    ->modalHeading(__('Export providers'))
                    ->modalDescription(__('The file contains the API keys in plain text. Keep it safe and delete it after the import.'))
                    ->modalSubmitActionLabel(__('Export'))
                    ->action(fn (ProviderTransfer $transfer): StreamedResponse => $this->downloadExport($transfer)),
                Action::make('import')
                    ->label(__('Import from file'))
                    ->color('gray')
                    ->groupedIcon(Heroicon::ArrowUpTray)
                    ->authorize('create')
                    ->modalHeading(__('Import providers'))
                    ->modalDescription(__('Providers whose UUID already exists here are skipped. New providers start out inactive.'))
                    ->modalSubmitActionLabel(__('Import from file'))
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('File'))
                            ->storeFiles(false)
                            ->acceptedFileTypes(['application/json', 'text/plain'])
                            ->maxSize(1024)
                            ->required(),
                    ])
                    ->action(fn (array $data, ProviderTransfer $transfer) => $this->importFile($data['file'], $transfer)),
            ])->color('gray'),
        ];
    }

    private function downloadExport(ProviderTransfer $transfer): StreamedResponse
    {
        $export = $transfer->export();
        $json = $export->toJson();
        $omitted = $export->omitted;

        if ($omitted > 0) {
            Notification::make()
                ->title($omitted === 1
                    ? __('1 provider with an unreadable key was not exported.')
                    : __(':count providers with an unreadable key were not exported.', ['count' => $omitted]))
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
    private function importFile(TemporaryUploadedFile $file, ProviderTransfer $transfer): void
    {
        try {
            $result = $transfer->import((string) $file->get());

            Notification::make()
                ->title(__(':created created, :skipped skipped, :renamed renamed', ['created' => $result->created, 'skipped' => $result->skipped, 'renamed' => $result->renamed]))
                ->success()
                ->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('Import failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $file->delete();
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match ([AiProvider::active() === null, AiProvider::activeForSearch() === null]) {
            [true, true] => (string) __('No AI is active for image recognition and search yet.'),
            [true, false] => (string) __('No AI is active for image recognition yet.'),
            [false, true] => (string) __('No AI is active for search yet.'),
            default => null,
        };
    }
}
