<?php

namespace App\Filament\Resources\Spots\Schemas;

use App\Models\Spot;
use Filament\Forms\Components\Select;

class SpotSelect
{
    public static function make(string $name = 'spot_id'): Select
    {
        return Select::make($name)
            ->label('Lagerplatz')
            ->required()
            ->searchable()
            ->options(fn (): array => Spot::query()->orderBy('id')->get()->pluck('label', 'id')->all())
            ->exists(Spot::class, 'id')
            ->createOptionForm(SpotForm::components())
            ->createOptionUsing(fn (array $data): int => Spot::create($data)->getKey());
    }
}
