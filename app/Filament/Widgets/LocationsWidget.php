<?php

namespace App\Filament\Widgets;

use App\Models\Location;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

class LocationsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.locations-widget';

    public static function canView(): bool
    {
        return Location::query()->exists();
    }

    /**
     * @return array{locations: Collection<int, Location>, largest: int}
     */
    protected function getViewData(): array
    {
        $locations = Location::query()->withCount('items')->orderByDesc('items_count')->orderBy('name')->get();

        return [
            'locations' => $locations,
            'largest' => max(1, (int) $locations->max('items_count')),
        ];
    }
}
