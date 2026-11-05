<?php

namespace App\Filament\Resources\Imports\Schemas;

use App\Enums\ImportStatus;
use App\Models\Import;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->extraAttributes(fn (Import $record): array => $record->status->isActive() ? ['wire:poll.5s' => ''] : [])
                    ->schema([
                        ImageEntry::make('photos')
                            ->label('Fotos')
                            ->disk('local')
                            ->visibility('private')
                            ->stacked(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('title')
                            ->label('Titel')
                            ->visible(fn (Import $record): bool => filled($record->title)),
                        TextEntry::make('description')
                            ->label('Beschreibung')
                            ->visible(fn (Import $record): bool => filled($record->description)),
                        TextEntry::make('error')
                            ->label('Fehler')
                            ->color('danger')
                            ->visible(fn (Import $record): bool => $record->status === ImportStatus::Failed)
                            ->state(fn (Import $record): ?string => app()->hasDebugModeEnabled()
                                ? $record->error
                                : 'Die Analyse ist fehlgeschlagen. Bitte erneut versuchen.'),
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
