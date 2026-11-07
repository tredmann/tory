<?php

namespace App\Filament\Resources\Spots\RelationManagers;

use App\Filament\Schemas\ItemGrid;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Gegenstände';

    public function table(Table $table): Table
    {
        return ItemGrid::configure($table)
            ->defaultSort('created_at', 'desc');
    }
}
