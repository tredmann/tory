<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Resources\Spots\Schemas\SpotSelect;
use App\Models\Item;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Radio::make('overview_photo')
                    ->label('Foto für die Übersicht')
                    ->options(fn (Item $record): array => self::photoOptions($record->photos))
                    ->in(fn (Item $record): array => $record->photos)
                    ->formatStateUsing(fn (Item $record): ?string => $record->photos[0] ?? null)
                    ->visible(fn (Item $record): bool => count($record->photos) > 1)
                    ->inline()
                    ->extraAttributes(['class' => 'tory-photo-picker'])
                    ->columnSpanFull(),
                TextInput::make('title')
                    ->label('Titel')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Beschreibung')
                    ->rows(5),
                TagsInput::make('search_terms')
                    ->label('Suchbegriffe')
                    ->splitKeys(['Tab', ',']),
                SpotSelect::make(),
            ]);
    }

    /**
     * The thumbnails as radio labels, keyed by photo path.
     *
     * @param  array<string>  $photos
     * @return array<string, HtmlString>
     */
    private static function photoOptions(array $photos): array
    {
        $options = [];

        foreach (array_values($photos) as $index => $photo) {
            $url = e(Storage::disk('local')->temporaryUrl($photo, now()->addMinutes(30)));
            $position = $index + 1;

            $options[$photo] = new HtmlString("<img src=\"{$url}\" alt=\"Foto {$position}\">");
        }

        return $options;
    }
}
