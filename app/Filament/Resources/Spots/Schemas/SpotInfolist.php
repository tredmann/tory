<?php

namespace App\Filament\Resources\Spots\Schemas;

use App\Models\Spot;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Code, name and location stand in the page header, so only a note is left to show.
 */
class SpotInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->visible(fn (Spot $record): bool => filled($record->note))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('note')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (string $state): string => nl2br(e($state)))
                            ->html(),
                    ]),
            ]);
    }
}
