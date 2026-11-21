<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Forms\Components\ItemPhotos;
use App\Filament\Resources\Spots\Schemas\SpotSelect;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ItemPhotos::make('photo_edits'),
                TextInput::make('title')
                    ->label(__('Title'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(5),
                Textarea::make('notes')
                    ->label(__('Note'))
                    ->rows(3),
                TagsInput::make('search_terms')
                    ->label(__('Search terms'))
                    ->splitKeys(['Tab', ',']),
                SpotSelect::make(),
            ]);
    }
}
