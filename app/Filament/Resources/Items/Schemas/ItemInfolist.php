<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Resources\StorageLocations\StorageLocationResource;
use App\Models\Item;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        ImageEntry::make('photos')
                            ->label('Fotos')
                            ->disk('local')
                            ->visibility('private')
                            ->stacked(),
                        TextEntry::make('title')
                            ->label('Titel'),
                        TextEntry::make('description')
                            ->label('Beschreibung')
                            ->placeholder('—')
                            ->extraAttributes(['style' => 'white-space: pre-line']),
                        TextEntry::make('storageLocation.label')
                            ->label('Lagerort')
                            ->url(fn (Item $record): string => StorageLocationResource::getUrl('view', ['record' => $record->storageLocation])),
                        TextEntry::make('created_at')
                            ->label('Angelegt')
                            ->dateTime(),
                    ]),
            ]);
    }
}
