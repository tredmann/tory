<?php

namespace App\Filament\Resources\Imports\Schemas;

use App\Enums\ImportStatus;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Schemas\PhotoSlideshow;
use App\Filament\Schemas\RegenerationEntries;
use App\Models\Import;
use App\Photos\PhotoLinks;
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
                            ->label(__('Photos'))
                            ->state(fn (Import $record): array => PhotoLinks::thumbnails($record->photos ?? []))
                            ->imageHeight(160)
                            ->wrap()
                            ->extraImgAttributes(fn (Import $record, string $state): array => PhotoSlideshow::imgAttributes($record->photos, $state, focusable: true)),
                        TextEntry::make('spot.full_label')
                            ->label(__('Spot'))
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge()
                            ->state(fn (Import $record): ImportStatus|string => $record->isRegenerating() ? RegenerationEntries::runningLabel() : $record->status)
                            ->color(fn (Import $record): ?string => $record->isRegenerating() ? 'warning' : null),
                        TextEntry::make('title')
                            ->label(__('Title'))
                            ->visible(fn (Import $record): bool => filled($record->title)),
                        TextEntry::make('description')
                            ->label(__('Description'))
                            ->extraAttributes(['style' => 'white-space: pre-line'])
                            ->visible(fn (Import $record): bool => filled($record->description)),
                        TextEntry::make('search_terms')
                            ->label(__('Search terms'))
                            ->badge()
                            ->visible(fn (Import $record): bool => filled($record->search_terms)),
                        TextEntry::make('failure_message')
                            ->label(__('Error'))
                            ->color('danger')
                            ->url(fn (): ?string => AiProviderResource::canAccess() ? AiProviderResource::getUrl('index') : null)
                            ->visible(fn (Import $record): bool => $record->status === ImportStatus::Failed && $record->failure_reason !== null)
                            ->state(fn (Import $record): string => $record->failureMessage().(AiProviderResource::canAccess() ? ' '.__('Check provider') : '')),
                        TextEntry::make('error')
                            ->label(__('Error'))
                            ->color('danger')
                            ->visible(fn (Import $record, Application $app): bool => $record->status === ImportStatus::Failed
                                && ($app->hasDebugModeEnabled() || $record->failure_reason === null))
                            ->state(fn (Import $record, Application $app): ?string => $app->hasDebugModeEnabled()
                                ? $record->error
                                : (string) __('The analysis failed. Please try again.')),
                        ...RegenerationEntries::failures(),
                        TextEntry::make('created_at')
                            ->label(__('Created'))
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label(__('Updated'))
                            ->dateTime(),
                    ]),
            ]);
    }
}
