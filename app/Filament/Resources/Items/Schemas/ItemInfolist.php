<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Resources\Spots\SpotResource;
use App\Filament\Schemas\PhotoSlideshow;
use App\Filament\Schemas\RegenerationEntries;
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
                    ->extraAttributes(fn (Item $record): array => $record->isRegenerating() ? ['wire:poll.5s' => ''] : [])
                    ->schema([
                        ImageEntry::make('photos')
                            ->label('Fotos')
                            ->disk('local')
                            ->visibility('private')
                            ->imageHeight(160)
                            ->wrap()
                            ->extraImgAttributes(fn (ImageEntry $entry, Item $record, string $state): array => PhotoSlideshow::imgAttributes($entry, $record->photos, $state)),
                        TextEntry::make('title')
                            ->label('Titel'),
                        TextEntry::make('description')
                            ->label('Beschreibung')
                            ->placeholder('—')
                            ->extraAttributes(['style' => 'white-space: pre-line']),
                        TextEntry::make('search_terms')
                            ->label('Suchbegriffe')
                            ->badge()
                            ->placeholder('—'),
                        ...RegenerationEntries::make(),
                        TextEntry::make('spot.full_label')
                            ->label('Lagerplatz')
                            ->url(fn (Item $record): string => SpotResource::getUrl('view', ['record' => $record->spot])),
                        TextEntry::make('created_at')
                            ->label('Angelegt')
                            ->dateTime(),
                    ]),
            ]);
    }
}
