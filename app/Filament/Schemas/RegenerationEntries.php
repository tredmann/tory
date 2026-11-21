<?php

namespace App\Filament\Schemas;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\Import;
use App\Models\Item;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Contracts\Foundation\Application;

class RegenerationEntries
{
    public static function runningLabel(): string
    {
        return __('Regenerating …');
    }

    /**
     * The running state, for a page without a status field of its own.
     */
    public static function running(): TextEntry
    {
        return TextEntry::make('regenerating_at')
            ->label(__('Regenerate'))
            ->badge()
            ->color('warning')
            ->state(self::runningLabel())
            ->visible(fn (Import|Item $record): bool => $record->isRegenerating());
    }

    /**
     * @return array<TextEntry>
     */
    public static function failures(): array
    {
        return [
            TextEntry::make('regeneration_failure_message')
                ->label(__('Regeneration failed'))
                ->color('danger')
                ->url(fn (): ?string => AiProviderResource::canAccess() ? AiProviderResource::getUrl('index') : null)
                ->visible(fn (Import|Item $record): bool => ! $record->isRegenerating() && $record->regeneration_failure_reason !== null)
                ->state(fn (Import|Item $record): string => $record->regenerationFailureMessage().(AiProviderResource::canAccess() ? ' '.__('Check provider') : '')),
            TextEntry::make('regeneration_error')
                ->label(__('Regeneration failed'))
                ->color('danger')
                ->visible(fn (Import|Item $record, Application $app): bool => ! $record->isRegenerating()
                    && filled($record->regeneration_error)
                    && ($app->hasDebugModeEnabled() || $record->regeneration_failure_reason === null))
                ->state(fn (Import|Item $record, Application $app): ?string => $app->hasDebugModeEnabled()
                    ? $record->regeneration_error
                    : (string) __('Regenerating failed. The previous texts are kept.')),
        ];
    }
}
