<?php

namespace App\Filament\Resources\Imports\Schemas;

use App\Filament\Resources\Spots\Schemas\SpotSelect;
use App\Models\Import;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;

class ImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpotSelect::make()
                    ->default(fn (): ?int => auth()->user()?->last_spot_id),
                FileUpload::make('photos')
                    ->label('Fotos')
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->validationMessages(['mimetypes' => 'Nur JPEG-, PNG-, WebP- oder GIF-Bilder sind erlaubt.'])
                    ->multiple()
                    ->required()
                    ->minFiles(1)
                    ->maxFiles(Import::MAX_PHOTOS)
                    ->maxSize(12288)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth('2048')
                    ->imageResizeTargetHeight('2048')
                    ->imageResizeUpscale(false)
                    ->reorderable()
                    ->panelLayout('grid'),
            ]);
    }
}
