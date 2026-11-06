<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageAiProviders extends ManageRecords
{
    protected static ?string $title = 'Eigene KI';

    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Provider anlegen')
                ->createAnother(false),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return AiProvider::active() === null
            ? 'Aktuell gilt die Serverkonfiguration (.env).'
            : null;
    }
}
