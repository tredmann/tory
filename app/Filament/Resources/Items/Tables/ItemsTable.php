<?php

namespace App\Filament\Resources\Items\Tables;

use App\Ai\ItemSearch;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\StorageLocations\StorageLocationResource;
use App\Models\Item;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsTable
{
    /**
     * @param  Builder<Item>  $query
     */
    private static function search(Builder $query, string $search, ItemSearch $itemSearch): void
    {
        $itemSearch->apply($query, $search);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('storageLocation'))
            ->columns([
                ImageColumn::make('photos')
                    ->label('Foto')
                    ->disk('local')
                    ->visibility('private')
                    ->limit(1),
                TextColumn::make('title')
                    ->label('Titel'),
                TextColumn::make('storageLocation.label')
                    ->label('Lagerort')
                    ->url(fn (Item $record): string => StorageLocationResource::getUrl('view', ['record' => $record->storageLocation])),
                TextColumn::make('created_at')
                    ->label('Angelegt')
                    ->dateTime()
                    ->sortable(),
            ])
            ->searchable()
            ->searchPlaceholder('Suchen …')
            ->searchUsing(self::search(...))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Item $record): string => ItemResource::getUrl('view', ['record' => $record]));
    }
}
