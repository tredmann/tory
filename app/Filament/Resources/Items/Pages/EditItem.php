<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $item = $this->getRecord();
        assert($item instanceof Item);

        if ($item->isRegenerating()) {
            $this->redirect(ItemResource::getUrl('view', ['record' => $item]));
        }
    }

    /**
     * A page opened before a run started must not save during it: the finished
     * job would overwrite the edit.
     */
    protected function beforeSave(): void
    {
        $item = $this->getRecord();
        assert($item instanceof Item);

        if (! Item::query()->whereKey($item->getKey())->whereNotNull('regenerating_at')->exists()) {
            return;
        }

        Notification::make()
            ->title('Wird gerade neu generiert. Deine Änderungen wurden nicht gespeichert.')
            ->danger()
            ->send();

        $this->redirect(ItemResource::getUrl('view', ['record' => $item]));

        $this->halt();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
