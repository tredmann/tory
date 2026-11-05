<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Jobs\ExtractImport;
use App\Models\Import;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

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
        foreach ($data['photos'] ?? [] as $path) {
            if (! is_string($path) || ! str_starts_with($path, 'imports/')) {
                throw ValidationException::withMessages(['data.photos' => 'Ungültiger Foto-Pfad.']);
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

        ExtractImport::dispatch($import);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Import angelegt';
    }
}
