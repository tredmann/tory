<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Filament\Forms\Components\BulkPhotos;
use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\BulkImports\Concerns\SitsBelowImports;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Models\BulkImport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBulkImport extends CreateRecord
{
    use SitsBelowImports, StartsBreadcrumbsAtHome;

    protected static string $resource = BulkImportResource::class;

    protected static ?string $title = 'Viele erfassen';

    protected static ?string $breadcrumb = 'Viele erfassen';

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        assert($user instanceof User);

        return BulkImport::start($user, (int) $data['spot_id'], BulkPhotos::stagedPhotos($data['photos'] ?? null));
    }

    protected function afterCreate(): void
    {
        /** @var BulkImport $bulkImport */
        $bulkImport = $this->getRecord();

        auth()->user()?->forceFill(['last_spot_id' => $bulkImport->spot_id])->save();
    }

    protected function getRedirectUrl(): string
    {
        return BulkImportResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Bulkimport angelegt';
    }

    /**
     * The button stays disabled while photos are still uploading.
     */
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Gruppieren')
            ->extraAttributes(['x-bind:disabled' => 'isProcessing || $store.toryPhotoUploads > 0']);
    }
}
