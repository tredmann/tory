<?php

namespace App\Filament\Resources\Spots\RelationManagers;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Gegenstände';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photos')
                    ->label('Foto')
                    ->disk('local')
                    ->visibility('private')
                    ->limit(1),
                TextColumn::make('title')
                    ->label('Titel'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Item $record): string => ItemResource::getUrl('view', ['record' => $record]));
    }
}
