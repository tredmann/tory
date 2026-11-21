<?php

namespace App\Filament\Resources\Spots\RelationManagers;

use App\Filament\Schemas\ItemGrid;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Items');
    }

    public function table(Table $table): Table
    {
        return ItemGrid::configure($table)
            ->defaultSort('created_at', 'desc');
    }
}
