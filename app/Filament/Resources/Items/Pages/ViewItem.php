<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Actions\RegenerateTextsAction;
use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Concerns\TitledByRecord;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Spots\SpotResource;
use App\Models\Item;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ViewItem extends ViewRecord
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome, TitledByRecord;

    protected static string $resource = ItemResource::class;

    /**
     * Where the item lies, written like the spot line of the item tiles.
     */
    public function getSubheading(): Htmlable
    {
        $item = $this->getRecord();
        assert($item instanceof Item);

        return new HtmlString(sprintf(
            '<a href="%s" class="tory-item-spot"><span class="tory-spot-code">%s</span> %s · %s</a>',
            e(SpotResource::getUrl('view', ['record' => $item->spot])),
            e($item->spot->code),
            e($item->spot->location->name),
            e($item->spot->name),
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                RegenerateTextsAction::make(),
                EditAction::make()
                    ->visible(fn (Item $record): bool => ! $record->isRegenerating()),
                DeleteAction::make(),
            ])->color('gray'),
        ];
    }
}
