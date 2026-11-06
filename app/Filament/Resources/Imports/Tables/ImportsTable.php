<?php

namespace App\Filament\Resources\Imports\Tables;

use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photos')
                    ->label('Foto')
                    ->disk('local')
                    ->visibility('private')
                    ->limit(1),
                TextColumn::make('title')
                    ->label('Titel')
                    ->placeholder('—'),
                TextColumn::make('storageLocation.label')
                    ->label('Lagerort')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Angelegt')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Import $record): string => ImportResource::getUrl('view', ['record' => $record]))
            ->poll('10s');
    }
}
