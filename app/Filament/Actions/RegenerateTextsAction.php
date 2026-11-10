<?php

namespace App\Filament\Actions;

use App\Enums\ImportStatus;
use App\Jobs\RegenerateTexts;
use App\Models\Import;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RegenerateTextsAction
{
    public static function make(): Action
    {
        return Action::make('regenerate')
            ->label('Neu generieren')
            ->color('gray')
            ->groupedIcon(Heroicon::ArrowPath)
            ->requiresConfirmation()
            ->modalHeading('Texte neu generieren?')
            ->modalDescription('Titel, Beschreibung und Suchbegriffe werden durch neue Vorschläge ersetzt, auch manuelle Änderungen. Das lässt sich nicht rückgängig machen. Mit einem kostenpflichtigen Provider kostet jeder Lauf Geld.')
            ->visible(function (Model $record): bool {
                if (! $record instanceof Import && ! $record instanceof Item) {
                    return false;
                }

                if ($record instanceof Import && $record->status !== ImportStatus::Completed) {
                    return false;
                }

                return filled($record->photos) && ! $record->isRegenerating();
            })
            ->action(function (Model $record): void {
                if (! $record instanceof Import && ! $record instanceof Item) {
                    return;
                }

                DB::transaction(function () use ($record): void {
                    if ($record->startRegeneration()) {
                        RegenerateTexts::dispatch($record)->beforeCommit();
                    }
                });

                Notification::make()
                    ->title('Neu generieren gestartet')
                    ->success()
                    ->send();
            });
    }
}
