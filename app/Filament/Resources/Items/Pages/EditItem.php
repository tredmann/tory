<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $overviewPhoto = Arr::pull($data, 'overview_photo');

        if ($overviewPhoto === null) {
            return $data;
        }

        $item = $this->getRecord();
        assert($item instanceof Item);

        $data['photos'] = [$overviewPhoto, ...array_values(array_diff($item->photos, [$overviewPhoto]))];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
