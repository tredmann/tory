<?php

namespace App\Filament\Resources\Imports\Schemas;

use App\Enums\ImportStatus;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Schemas\RegenerationEntries;
use App\Models\Import;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Foundation\Application;

class ImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->extraAttributes(fn (Import $record): array => $record->status->isActive() || $record->isRegenerating() ? ['wire:poll.5s' => ''] : [])
                    ->schema([
                        ImageEntry::make('photos')
                            ->label('Fotos')
                            ->disk('local')
                            ->visibility('private')
                            ->stacked(),
                        TextEntry::make('spot.full_label')
                            ->label('Lagerplatz')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('title')
                            ->label('Titel')
                            ->visible(fn (Import $record): bool => filled($record->title)),
                        TextEntry::make('description')
                            ->label('Beschreibung')
                            ->extraAttributes(['style' => 'white-space: pre-line'])
                            ->visible(fn (Import $record): bool => filled($record->description)),
                        TextEntry::make('search_terms')
                            ->label('Suchbegriffe')
                            ->badge()
                            ->visible(fn (Import $record): bool => filled($record->search_terms)),
                        TextEntry::make('failure_message')
                            ->label('Fehler')
                            ->color('danger')
                            ->url(AiProviderResource::getUrl('index'))
                            ->visible(fn (Import $record): bool => $record->status === ImportStatus::Failed && $record->failure_reason !== null)
                            ->state(fn (Import $record): string => $record->failureMessage().' Provider prüfen'),
                        TextEntry::make('error')
                            ->label('Fehler')
                            ->color('danger')
                            ->visible(fn (Import $record, Application $app): bool => $record->status === ImportStatus::Failed
                                && ($app->hasDebugModeEnabled() || $record->failure_reason === null))
                            ->state(fn (Import $record, Application $app): ?string => $app->hasDebugModeEnabled()
                                ? $record->error
                                : 'Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.'),
                        ...RegenerationEntries::make(),
                        TextEntry::make('created_at')
                            ->label('Angelegt')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Aktualisiert')
                            ->dateTime(),
                    ]),
            ]);
    }
}
