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
                    ->label(__('Spots'))
                    ->counts('spots'),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label(__('Edit')),
                    DeleteAction::make()
                        ->label(__('Delete'))
                        ->disabled(fn (Location $record): bool => $record->spots()->exists())
                        ->tooltip(fn (Location $record): ?string => $record->spots()->exists() ? (string) __('Still contains spots') : null),
                ])->color('gray'),
            ]);
    }
}
