<?php

namespace App\Filament\Resources\Imports\Tables;

use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Schemas\PhotoSlideshow;
use App\Models\Import;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('spot.location'))
            ->columns([
                ImageColumn::make('photos')
                    ->label('Foto')
                    ->disk('local')
                    ->visibility('private')
                    ->imageHeight(80)
                    ->limit(1)
                    ->extraImgAttributes(fn (ImageColumn $column, Import $record, string $state): array => PhotoSlideshow::imgAttributes($column, $record->photos, $state)),
                TextColumn::make('title')
                    ->label('Titel')
                    ->placeholder('—'),
                TextColumn::make('spot.full_label')
                    ->label('Lagerplatz')
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
