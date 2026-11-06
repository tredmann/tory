<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Resources\StorageLocations\Schemas\StorageLocationSelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Titel')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Beschreibung')
                    ->rows(5),
                StorageLocationSelect::make(),
            ]);
    }
}
