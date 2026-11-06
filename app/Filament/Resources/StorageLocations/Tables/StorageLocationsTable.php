<?php

namespace App\Filament\Resources\StorageLocations\Tables;

use App\Filament\Resources\StorageLocations\StorageLocationResource;
use App\Models\StorageLocation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StorageLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('note')
                    ->label('Notiz')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('items_count')
                    ->label('Gegenstände')
                    ->counts('items'),
            ])
            ->defaultSort('id')
            ->recordUrl(fn (StorageLocation $record): string => StorageLocationResource::getUrl('view', ['record' => $record]));
    }
}
