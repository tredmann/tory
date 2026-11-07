<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Resources\Spots\SpotResource;
use App\Filament\Schemas\PhotoSlideshow;
use App\Filament\Schemas\RegenerationEntries;
use App\Models\Item;
use App\Photos\PhotoLinks;
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
                            ->state(fn (Item $record): array => array_map(PhotoLinks::thumbnail(...), PhotoLinks::existing($record->photos ?? [])))
                            ->imageHeight(160)
                            ->wrap()
                            ->extraImgAttributes(fn (Item $record, string $state): array => PhotoSlideshow::imgAttributes($record->photos, $state)),
                        RegenerationEntries::running(),
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
                        ...RegenerationEntries::failures(),
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
