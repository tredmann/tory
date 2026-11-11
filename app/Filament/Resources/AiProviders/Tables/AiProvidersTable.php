<?php

namespace App\Filament\Resources\AiProviders\Tables;

use App\Ai\ProviderTester;
use App\Ai\SearchProviderSwitch;
use App\Enums\AiVendor;
use App\Filament\Resources\AiProviders\Schemas\AiProviderForm;
use App\Models\AiProvider;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class AiProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    TextColumn::make('name')
                        ->weight(FontWeight::Bold),
                    TextColumn::make('vendor'),
                    TextColumn::make('model')
                        ->prefix('Bilderkennung: '),
                    TextColumn::make('embedding_model')
                        ->prefix('Embedding: '),
                    TextColumn::make('connection')
                        ->state(fn (AiProvider $record): string => match (true) {
                            $record->vendor === AiVendor::Ollama => (string) $record->url,
                            $record->hasUnreadableKey() => 'Key nicht lesbar, bitte neu eingeben',
                            default => '••••'.$record->key_hint,
                        })
                        ->color(fn (AiProvider $record): ?string => $record->hasUnreadableKey() ? 'danger' : null),
                    TextColumn::make('is_active')
                        ->state(fn (AiProvider $record): ?string => $record->is_active ? 'Aktiv' : null)
                        ->badge()
                        ->color('success'),
                    TextColumn::make('is_active_for_search')
                        ->state(fn (AiProvider $record): ?string => $record->is_active_for_search ? 'Suche aktiv' : null)
                        ->badge()
                        ->color('success'),
                    TextColumn::make('search_status')
                        ->state(fn (AiProvider $record): ?string => $record->is_active_for_search ? self::searchProgress() : null),
                    TextColumn::make('search_failure')
                        ->state(fn (AiProvider $record): ?string => $record->is_active_for_search && filled($record->search_failure)
                            ? "{$record->search_failure} Es wird stündlich erneut versucht."
                            : null)
                        ->color('danger'),
                ]),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->extraAttributes(['class' => 'tory-provider-grid'])
            ->paginated(false)
            ->defaultSort('name')
            ->poll(fn (): ?string => AiProvider::activeForSearch() !== null && Item::query()->whereNull('embedding')->exists() ? '5s' : null)
            ->recordClasses(fn (AiProvider $record): ?string => $record->is_active || $record->is_active_for_search ? 'tory-active-provider' : null)
            ->recordActions([
                ActionGroup::make([
                    Action::make('activate')
                        ->authorize('update')
                        ->label('Aktivieren')
                        ->groupedIcon(Heroicon::CheckCircle)
                        ->hidden(fn (AiProvider $record): bool => $record->is_active || blank($record->model))
                        ->action(function (AiProvider $record): void {
                            $record->activate();

                            Notification::make()
                                ->title('Provider aktiviert')
                                ->success()
                                ->send();
                        }),
                    Action::make('activateForSearch')
                        ->authorize('update')
                        ->label('Für Suche aktivieren')
                        ->groupedIcon(Heroicon::MagnifyingGlass)
                        ->hidden(fn (AiProvider $record): bool => $record->is_active_for_search || blank($record->embedding_model))
                        ->requiresConfirmation()
                        ->modalHeading('Für Suche aktivieren')
                        ->modalDescription(fn (AiProvider $record): string => AiProviderForm::switchNotice(
                            AiProvider::activeForSearch()->embedding_model ?? config('ai.inventory.embedding_model').' (.env)',
                            (string) $record->embedding_model,
                        ))
                        ->modalSubmitActionLabel('Aktivieren und neu berechnen')
                        ->mountUsing(function (Action $action, AiProvider $record, SearchProviderSwitch $switch): void {
                            $reason = $switch->probe($record, (string) $record->embedding_model);

                            if ($reason !== null) {
                                self::notifyProbeFailure($reason);
                                $action->cancel();
                            }
                        })
                        ->action(function (Action $action, AiProvider $record, SearchProviderSwitch $switch): void {
                            $record->refresh();
                            $embeddingModel = $record->embedding_model;

                            if (blank($embeddingModel)) {
                                self::notifyProbeFailure('Für diesen Provider ist kein Embedding-Modell mehr hinterlegt.');
                                $action->cancel();
                            }

                            $reason = $switch->probe($record, (string) $embeddingModel);

                            if ($reason !== null) {
                                self::notifyProbeFailure($reason);
                                $action->cancel();
                            }

                            $switch->switchTo($record, (string) $embeddingModel);

                            Notification::make()
                                ->title('Suche wird neu aufgebaut')
                                ->success()
                                ->send();
                        }),
                    Action::make('test')
                        ->authorize('update')
                        ->label('Testen')
                        ->groupedIcon(Heroicon::Signal)
                        ->action(function (AiProvider $record, ProviderTester $tester): void {
                            $reason = $tester->run($record);

                            if ($reason === null) {
                                Notification::make()
                                    ->title(filled($record->embedding_model)
                                        ? "{$record->name} hat den Test bestanden."
                                        : "{$record->name} hat das Testbild beantwortet.")
                                    ->success()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Test fehlgeschlagen')
                                ->body($reason)
                                ->danger()
                                ->send();
                        }),
                    EditAction::make()
                        ->label('Bearbeiten')
                        ->fillForm(fn (AiProvider $record): array => [
                            'name' => $record->name,
                            'vendor' => $record->vendor,
                            'url' => $record->url,
                            'model' => $record->model,
                            'embedding_model' => $record->embedding_model,
                            'search_min_similarity' => $record->search_min_similarity ?? AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY,
                        ])
                        ->using(self::saveProvider(...)),
                    DeleteAction::make()
                        ->label('Löschen')
                        ->disabled(fn (AiProvider $record): bool => $record->is_active || $record->is_active_for_search)
                        ->tooltip(fn (AiProvider $record): ?string => $record->is_active || $record->is_active_for_search ? 'Erst einen anderen Provider aktivieren' : null),
                ])->color('gray'),
            ]);
    }

    /**
     * A changed embedding model on the provider active for search is a switch:
     * it is probed with the submitted values, so a typed key counts, and either
     * everything is saved together with the switch or nothing is.
     *
     * @param  array<string, mixed>  $data
     */
    private static function saveProvider(array $data, AiProvider $record, Action $action, SearchProviderSwitch $switch): void
    {
        $embeddingModel = $data['embedding_model'] ?? null;

        if (! $record->is_active_for_search || ! is_string($embeddingModel) || $embeddingModel === $record->embedding_model) {
            $record->update($data);

            return;
        }

        $record->fill($data);
        $reason = $switch->probe($record, $embeddingModel);

        if ($reason !== null) {
            $record->refresh();
            self::notifyProbeFailure($reason);
            $action->halt();
        }

        DB::transaction(function () use ($record, $switch, $embeddingModel): void {
            $record->save();
            $switch->switchTo($record, $embeddingModel);
        });
    }

    private static function notifyProbeFailure(string $reason): void
    {
        Notification::make()
            ->title('Test fehlgeschlagen')
            ->body($reason)
            ->danger()
            ->send();
    }

    private static function searchProgress(): ?string
    {
        $total = Item::query()->count();
        $done = Item::query()->whereNotNull('embedding')->count();

        return $done < $total ? "Suche wird neu aufgebaut: {$done} von {$total}" : null;
    }
}
