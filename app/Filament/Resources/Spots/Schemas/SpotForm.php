<?php

namespace App\Filament\Resources\Spots\Schemas;

use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class SpotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * @return array<Component>
     */
    public static function components(): array
    {
        return [
            Select::make('location_id')
                ->label('Lagerort')
                ->required()
                ->searchable()
                ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                ->exists(Location::class, 'id')
                ->actionSchemaModel(Location::class)
                ->createOptionForm(LocationForm::components())
                ->createOptionUsing(fn (array $data): string => Location::create($data)->getKey())
                ->default(fn (): ?string => Location::query()->count() === 1 ? Location::query()->value('id') : null),
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            Textarea::make('note')
                ->label('Notiz'),
        ];
    }
}
