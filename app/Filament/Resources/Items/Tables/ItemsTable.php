<?php

namespace App\Filament\Resources\Items\Tables;

use App\Ai\ItemSearch;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Spots\SpotResource;
use App\Models\Item;
use App\Models\Location;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('spot.location'))
            ->columns([
                ImageColumn::make('photos')
                    ->label('Foto')
                    ->disk('local')
                    ->visibility('private')
                    ->limit(1),
                TextColumn::make('title')
                    ->label('Titel'),
                TextColumn::make('spot.full_label')
                    ->label('Lagerplatz')
                    ->url(fn (Item $record): string => SpotResource::getUrl('view', ['record' => $record->spot])),
                TextColumn::make('created_at')
                    ->label('Angelegt')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('location')
                    ->label('Lagerort')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('spot', fn (Builder $spot): Builder => $spot->where('location_id', $data['value']))
                        : $query),
            ])
            ->searchable()
            ->searchPlaceholder('Suchen …')
            ->searchUsing(self::search(...))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Item $record): string => ItemResource::getUrl('view', ['record' => $record]));
    }
}
