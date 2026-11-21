<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportStatus;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\ExtractImport;
use App\Jobs\GenerateThumbnail;
use App\Models\Import;
use App\Photos\PhotoStore;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateImport extends CreateRecord
{
    use StartsBreadcrumbsAtHome;

    protected static string $resource = ImportResource::class;

    public function getTitle(): string
    {
        return __('Add item');
    }

    public function getBreadcrumb(): string
    {
        return __('Add item');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        foreach ($data['photos'] ?? [] as $path) {
            if (! is_string($path) || ! PhotoStore::isUploadPath($path)) {
                throw ValidationException::withMessages(['data.photos' => __('Invalid photo path.')]);
            }
        }

        $data['user_id'] = auth()->id();
        $data['status'] = ImportStatus::Pending;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Import $import */
        $import = $this->getRecord();

        auth()->user()?->forceFill(['last_spot_id' => $import->spot_id])->save();

        ExtractImport::dispatch($import);

        foreach ($import->photos ?? [] as $path) {
            GenerateThumbnail::dispatch($path);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Import created');
    }
}
