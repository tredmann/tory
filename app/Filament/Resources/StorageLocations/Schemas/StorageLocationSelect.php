<?php

namespace App\Filament\Resources\StorageLocations\Schemas;

use App\Models\StorageLocation;
use Filament\Forms\Components\Select;

class StorageLocationSelect
{
    public static function make(string $name = 'storage_location_id'): Select
    {
        return Select::make($name)
            ->label('Lagerort')
            ->required()
            ->searchable()
            ->options(fn (): array => StorageLocation::query()->orderBy('id')->get()->pluck('label', 'id')->all())
            ->exists(StorageLocation::class, 'id')
            ->createOptionForm(StorageLocationForm::components())
            ->createOptionUsing(fn (array $data): int => StorageLocation::create($data)->getKey());
    }
}
