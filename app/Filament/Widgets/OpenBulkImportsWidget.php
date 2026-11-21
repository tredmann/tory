<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Models\BulkImport;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenBulkImportsWidget extends TableWidget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return BulkImport::query()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => BulkImport::query()->latest())
            ->heading(__('Open bulk imports'))
            ->columns([
                Split::make([
                    TextColumn::make('photos')
                        ->state(fn (BulkImport $record): string => trans_choice(':count photo|:count photos', count($record->photos))),
                    TextColumn::make('status')
                        ->badge()
                        ->grow(false),
                ]),
            ])
            ->paginated(false)
            ->recordUrl(fn (BulkImport $record): string => BulkImportResource::getUrl('view', ['record' => $record]))
            ->poll('10s');
    }
}
