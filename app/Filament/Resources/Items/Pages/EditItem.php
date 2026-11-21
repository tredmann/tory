<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Forms\Components\ItemPhotos;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditItem extends EditRecord
{
    use StartsBreadcrumbsAtHome;

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
            ->title(__('This item is being regenerated. Your changes were not saved.'))
            ->danger()
            ->send();

        $this->redirect(ItemResource::getUrl('view', ['record' => $item]));

        $this->halt();
    }

    /**
     * Title and photos are saved together or not at all.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Item);

        /** @var array{overview?: ?string, removed?: list<string>, uploads?: array<string, mixed>}|null $edits */
        $edits = Arr::pull($data, 'photo_edits');
        $removed = $edits['removed'] ?? [];
        $uploads = ItemPhotos::keptUploads($edits);

        DB::transaction(function () use ($record, $data, $edits, $removed, $uploads): void {
            $record->update($data);
            $record->changePhotos($removed, $uploads, $edits['overview'] ?? null);
        });

        return $record;
    }

    protected function afterSave(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
        $this->dispatch('item-photos-saved');
    }

    /**
     * Replaces the button's own binding, which would otherwise only cover the save itself.
     */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->extraAttributes(['x-bind:disabled' => 'isProcessing || $store.toryPhotoUploads > 0']);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
