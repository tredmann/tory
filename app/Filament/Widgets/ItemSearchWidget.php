<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Items\ItemResource;
use Filament\Widgets\Widget;

class ItemSearchWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.item-search-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{action: string}
     */
    protected function getViewData(): array
    {
        return ['action' => ItemResource::getUrl('index')];
    }
}
