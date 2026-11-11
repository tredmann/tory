<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Location;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('spots_count')
                    ->label('Lagerplätze')
                    ->counts('spots'),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Bearbeiten'),
                    DeleteAction::make()
                        ->label('Löschen')
                        ->disabled(fn (Location $record): bool => $record->spots()->exists())
                        ->tooltip(fn (Location $record): ?string => $record->spots()->exists() ? 'Enthält noch Lagerplätze' : null),
                ])->color('gray'),
            ]);
    }
}
