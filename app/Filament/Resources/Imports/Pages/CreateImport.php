<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\ExtractImport;
use App\Models\Import;
use Filament\Resources\Pages\CreateRecord;

class CreateImport extends CreateRecord
{
    protected static string $resource = ImportResource::class;

    protected static ?string $title = 'Erfassen';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['status'] = ImportStatus::Pending;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Import $import */
        $import = $this->getRecord();

        ExtractImport::dispatch($import);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Import angelegt';
    }
}
