<?php

namespace App\Filament\Resources\StorageLocations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StorageLocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Code'),
                        TextEntry::make('name')
                            ->label('Name'),
                        TextEntry::make('note')
                            ->label('Notiz')
                            ->placeholder('—')
                            ->extraAttributes(['style' => 'white-space: pre-line']),
                    ]),
            ]);
    }
}
