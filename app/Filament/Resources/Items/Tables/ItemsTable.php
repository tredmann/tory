<?php

namespace App\Filament\Resources\Items\Tables;

use App\Ai\ItemSearch;
use App\Filament\Schemas\ItemGrid;
use App\Models\Item;
use App\Models\Location;
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
        return ItemGrid::configure($table, [
            TextColumn::make('created_at')
                ->label(__('Created'))
                ->dateTime()
                ->sortable()
                ->extraAttributes(['class' => 'tory-visually-hidden']),
        ])
            ->filters([
                SelectFilter::make('location')
                    ->label(__('Location'))
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('spot', fn (Builder $spot): Builder => $spot->where('location_id', $data['value']))
                        : $query),
            ])
            ->searchable()
            ->searchPlaceholder(__('Search …'))
            ->searchUsing(self::search(...))
            ->defaultSort('created_at', 'desc');
    }
}
