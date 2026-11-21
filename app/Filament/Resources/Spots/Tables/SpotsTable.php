<?php

namespace App\Filament\Resources\Spots\Tables;

use App\Filament\Actions\SpotLabelsAction;
use App\Filament\Resources\Spots\SpotResource;
use App\Models\Spot;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SpotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('location'))
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('location.name')
                    ->label(__('Location')),
                TextColumn::make('note')
                    ->label(__('Spot note'))
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->counts('items'),
            ])
            ->defaultSort('id')
            ->toolbarActions([
                SpotLabelsAction::bulk(),
            ])
            ->recordUrl(fn (Spot $record): string => SpotResource::getUrl('view', ['record' => $record]));
    }
}
