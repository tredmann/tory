<?php

namespace App\Filament\Resources\BulkImports\Schemas;

use App\Filament\Forms\Components\BulkPhotos;
use App\Filament\Resources\Spots\Schemas\SpotSelect;
use Filament\Schemas\Schema;

class BulkImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpotSelect::make()
                    ->default(fn (): ?int => auth()->user()?->last_spot_id),
                BulkPhotos::make('photos'),
            ]);
    }
}
