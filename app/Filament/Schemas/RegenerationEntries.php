<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\Import;
use App\Models\Item;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Contracts\Foundation\Application;

class RegenerationEntries
{
    public const string RUNNING_LABEL = 'Wird neu generiert …';

    /**
     * The running state, for a page without a status field of its own.
     */
    public static function running(): TextEntry
    {
        return TextEntry::make('regenerating_at')
            ->label('Neu generieren')
            ->badge()
            ->color('warning')
            ->state(self::RUNNING_LABEL)
            ->visible(fn (Import|Item $record): bool => $record->isRegenerating());
    }

    /**
     * @return array<TextEntry>
     */
    public static function failures(): array
    {
        return [
            TextEntry::make('regeneration_failure_message')
                ->label('Fehler beim Neu generieren')
                ->color('danger')
                ->url(AiProviderResource::getUrl('index'))
                ->visible(fn (Import|Item $record): bool => ! $record->isRegenerating() && $record->regeneration_failure_reason !== null)
                ->state(fn (Import|Item $record): string => $record->regenerationFailureMessage().' Provider prüfen'),
            TextEntry::make('regeneration_error')
                ->label('Fehler beim Neu generieren')
                ->color('danger')
                ->visible(fn (Import|Item $record, Application $app): bool => ! $record->isRegenerating()
                    && filled($record->regeneration_error)
                    && ($app->hasDebugModeEnabled() || $record->regeneration_failure_reason === null))
                ->state(fn (Import|Item $record, Application $app): ?string => $app->hasDebugModeEnabled()
                    ? $record->regeneration_error
                    : 'Neu generieren ist fehlgeschlagen. Die bisherigen Texte sind erhalten.'),
        ];
    }
}
