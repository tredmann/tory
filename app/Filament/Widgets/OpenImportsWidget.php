<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use Filament\Actions\Action;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenImportsWidget extends TableWidget
{
    private const int LIMIT = 5;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Import::query()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Import::query()->latest()->limit(self::LIMIT))
            ->heading('Offene Importe')
            ->columns([
                Split::make([
                    TextColumn::make('title')
                        ->placeholder('—')
                        ->lineClamp(1),
                    TextColumn::make('status')
                        ->badge()
                        ->grow(false),
                ]),
            ])
            ->headerActions([
                Action::make('all')
                    ->label('Alle Importe')
                    ->link()
                    ->url(ImportResource::getUrl('index'))
                    ->visible(fn (): bool => Import::query()->count() > self::LIMIT),
            ])
            ->paginated(false)
            ->recordUrl(fn (Import $record): string => ImportResource::getUrl('view', ['record' => $record]))
            ->poll('10s');
    }
}
