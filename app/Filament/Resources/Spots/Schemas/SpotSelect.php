<?php

namespace App\Filament\Resources\Spots\Schemas;

use App\Models\Spot;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class SpotSelect
{
    public static function make(string $name = 'spot_id'): Select
    {
        return Select::make($name)
            ->label(__('Spot'))
            ->required()
            ->searchable()
            ->optionsLimit(1000)
            ->options(fn (): array => self::options())
            ->getSearchResultsUsing(fn (string $search): array => self::options($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => Spot::query()->whereKey($value)->first()?->label)
            ->exists(Spot::class, 'id')
            ->createOptionForm(SpotForm::components())
            ->createOptionUsing(fn (array $data): int => Spot::create($data)->getKey());
    }

    /**
     * Spots grouped by location name; every word of the search must match the
     * spot's code, its name or its location's name.
     *
     * @return array<string, array<int, string>>
     */
    public static function options(?string $search = null): array
    {
        $words = preg_split('/\s+/u', $search ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $spots = Spot::query()
            ->select('spots.*', 'locations.name as location_name')
            ->join('locations', 'locations.id', '=', 'spots.location_id')
            ->where(function (Builder $query) use ($words): void {
                foreach ($words as $word) {
                    $pattern = '%'.addcslashes($word, '\\%_').'%';
                    $query->whereRaw(
                        '(spots.code ilike ? or spots.name ilike ? or locations.name ilike ?)',
                        [$pattern, $pattern, $pattern],
                    );
                }
            })
            ->orderBy('locations.name')
            ->orderBy('spots.name')
            ->orderBy('spots.code')
            ->get();

        $options = [];

        foreach ($spots as $spot) {
            $options[$spot->getAttribute('location_name')][$spot->id] = $spot->label;
        }

        return $options;
    }
}
