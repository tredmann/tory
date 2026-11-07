<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Spots\SpotResource;
use App\Models\Item;
use App\Photos\PhotoLinks;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Component;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemGrid
{
    /**
     * Lays a table of items out as photo tiles, the same on every list: photo, title and
     * where the item lies, then the given details.
     *
     * @param  array<Column|Component>  $details
     */
    public static function configure(Table $table, array $details = []): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('spot.location'))
            ->columns([
                Stack::make([
                    ImageColumn::make('photos')
                        ->state(fn (Item $record): array => array_map(PhotoLinks::thumbnail(...), PhotoLinks::existing($record->photos ?? [])))
                        ->imageWidth('100%')
                        ->imageHeight('auto')
                        ->limit(1)
                        ->placeholder('Kein Foto')
                        ->extraImgAttributes(fn (Item $record, string $state): array => PhotoSlideshow::imgAttributes($record->photos, $state)),
                    TextColumn::make('title')
                        ->weight(FontWeight::Medium)
                        ->lineClamp(1),
                    self::spot(),
                    ...$details,
                ]),
            ])
            ->contentGrid(['default' => 2, 'lg' => 4])
            ->paginationPageOptions([12, 24, 48])
            ->extraAttributes(['class' => 'tory-item-grid'])
            ->recordUrl(fn (Item $record): string => ItemResource::getUrl('view', ['record' => $record]));
    }

    /**
     * Where the item lies, linking to its spot: the code, then location and spot name.
     */
    private static function spot(): Split
    {
        return Split::make([
            TextColumn::make('spot.code')
                ->fontFamily(FontFamily::Mono)
                ->extraAttributes(['class' => 'tory-spot-code'])
                ->url(fn (Item $record): string => SpotResource::getUrl('view', ['record' => $record->spot]))
                ->grow(false),
            TextColumn::make('where')
                ->state(fn (Item $record): string => "{$record->spot->location->name} · {$record->spot->name}")
                ->url(fn (Item $record): string => SpotResource::getUrl('view', ['record' => $record->spot]))
                ->color('gray')
                ->lineClamp(2),
        ]);
    }
}
