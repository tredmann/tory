<?php

namespace App\Filament\Resources\Spots\Pages;

use App\Filament\Actions\SpotLabelsAction;
use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Concerns\TitledByRecord;
use App\Filament\Resources\Spots\SpotResource;
use App\Models\Spot;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;

class ViewSpot extends ViewRecord
{
    use OmitsPageBreadcrumb, StartsBreadcrumbsAtHome, TitledByRecord;

    protected static string $resource = SpotResource::class;

    public function getSubheading(): ?string
    {
        $spot = $this->getRecord();
        assert($spot instanceof Spot);

        return $spot->location->name;
    }

    /**
     * The infolist holds only the note; without one Filament would fall back to showing the form.
     */
    protected function hasInfolist(): bool
    {
        return true;
    }

    /**
     * An empty infolist would still take up a gap above the items.
     */
    public function getInfolistContentComponent(): Component
    {
        return parent::getInfolistContentComponent()
            ->visible(fn (): bool => $this->getSchema('infolist')->getComponents() !== []);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                SpotLabelsAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, Spot $record): void {
                        $reason = match (true) {
                            $record->items()->exists() => (string) __('The spot still contains items and cannot be deleted.'),
                            $record->bulkImports()->exists() => (string) __('This spot is still used by a bulk import.'),
                            default => null,
                        };

                        if ($reason === null) {
                            return;
                        }

                        Notification::make()->title($reason)->danger()->send();

                        $action->halt();
                    }),
            ])->color('gray'),
        ];
    }
}
