<?php

namespace App\Filament\Resources\Imports\Tables;

use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Schemas\PhotoSlideshow;
use App\Models\Import;
use App\Photos\PhotoLinks;
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
                    ->label(__('Photo'))
                    ->state(fn (Import $record): array => PhotoLinks::thumbnails($record->photos ?? []))
                    ->imageHeight(80)
                    ->limit(1)
                    ->extraImgAttributes(fn (Import $record, string $state): array => PhotoSlideshow::imgAttributes($record->photos, $state)),
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->placeholder('—'),
                TextColumn::make('spot.full_label')
                    ->label(__('Spot'))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Import $record): string => ImportResource::getUrl('view', ['record' => $record]))
            ->poll('10s');
    }
}
