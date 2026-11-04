<?php

namespace App\Filament\Pages;

use App\Ai\Agents\InventoryExtractor;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Laravel\Ai\Files\Image;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

/**
 * @property-read Schema $form
 */
class ExtractItem extends Page
{
    protected static ?string $slug = 'erfassen';

    protected static ?string $title = 'Erfassen';

    protected static ?string $navigationLabel = 'Erfassen';

    protected string $view = 'filament.pages.extract-item';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $extractedTitle = null;

    public ?string $extractedDescription = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('photos')
                    ->label('Fotos')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->validationMessages(['mimetypes' => 'Nur JPEG-, PNG-, WebP- oder GIF-Bilder sind erlaubt.'])
                    ->multiple()
                    ->required()
                    ->minFiles(1)
                    ->maxFiles(6)
                    ->maxSize(12288)
                    ->storeFiles(false)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('2048')
                    ->imageResizeTargetHeight('2048')
                    ->imageResizeUpscale(false)
                    ->reorderable()
                    ->panelLayout('grid'),
            ])
            ->statePath('data');
    }

    public function analyze(): void
    {
        /** @var array<int, TemporaryUploadedFile> $photos */
        $photos = $this->form->getState()['photos'];

        $this->extractedTitle = null;
        $this->extractedDescription = null;

        try {
            $attachments = array_map(
                fn (TemporaryUploadedFile $file) => Image::fromPath($file->getRealPath()),
                array_values($photos),
            );

            $result = InventoryExtractor::make()
                ->prompt('Analysiere die Fotos und liefere Titel und Beschreibung.', $attachments)
                ->toArray();

            $title = $result['title'] ?? null;
            $description = $result['description'] ?? null;

            if (! is_string($title) || trim($title) === '' || ! is_string($description) || trim($description) === '') {
                throw new RuntimeException('Das Modell hat keine vollständige Antwort geliefert.');
            }

            $this->extractedTitle = $title;
            $this->extractedDescription = $description;
        } catch (Throwable $e) {
            report($e);

            $this->extractedTitle = null;
            $this->extractedDescription = null;

            Notification::make()
                ->title('Analyse fehlgeschlagen')
                ->body(app()->hasDebugModeEnabled() ? $e->getMessage() : null)
                ->danger()
                ->send();
        } finally {
            foreach ($photos as $file) {
                try {
                    $file->delete();
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $this->form->fill();
        }
    }
}
