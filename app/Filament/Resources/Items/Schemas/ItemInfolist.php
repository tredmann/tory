<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Schemas\PhotoSlideshow;
use App\Filament\Schemas\RegenerationEntries;
use App\Models\Item;
use App\Photos\PhotoLinks;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(1)
                    ->columnSpanFull()
                    ->extraAttributes(fn (Item $record): array => $record->isRegenerating() ? ['wire:poll.5s' => ''] : [])
                    ->schema([
                        Section::make()->schema([
                            ImageEntry::make('photos')
                                ->label(__('Photos'))
                                ->state(fn (Item $record): array => PhotoLinks::thumbnails($record->photos ?? []))
                                ->imageHeight(160)
                                ->wrap()
                                ->extraImgAttributes(fn (Item $record, string $state): array => PhotoSlideshow::imgAttributes($record->photos, $state)),
                        ]),
                        Grid::make(['default' => 1, 'lg' => 3])
                            ->extraAttributes(['class' => 'tory-item-boxes'])
                            ->schema([
                                Section::make()
                                    ->columnSpan(['lg' => 2])
                                    ->visible(fn (Item $record): bool => self::hasTexts($record))
                                    ->schema([
                                        RegenerationEntries::running(),
                                        self::multiline(TextEntry::make('description'))
                                            ->label(__('Description'))
                                            ->visible(fn (Item $record): bool => filled($record->description)),
                                        self::multiline(TextEntry::make('notes'))
                                            ->label(__('Note'))
                                            ->visible(fn (Item $record): bool => filled($record->notes)),
                                        ...RegenerationEntries::failures(),
                                    ]),
                                Section::make()
                                    ->columnStart(['lg' => 3])
                                    ->schema([
                                        TextEntry::make('search_terms')
                                            ->label(__('Search terms'))
                                            ->color('gray')
                                            ->visible(fn (Item $record): bool => filled($record->search_terms)),
                                        TextEntry::make('created_at')
                                            ->label(__('Created'))
                                            ->dateTime(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Whether the left box has anything to show: a text or the state of "Neu generieren".
     */
    private static function hasTexts(Item $record): bool
    {
        return filled($record->description)
            || filled($record->notes)
            || $record->isRegenerating()
            || $record->regeneration_failure_reason !== null
            || filled($record->regeneration_error);
    }

    /**
     * Keeps the line breaks of a text; a CSS white-space rule would also show the line breaks of the markup.
     */
    private static function multiline(TextEntry $entry): TextEntry
    {
        return $entry->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))));
    }
}
