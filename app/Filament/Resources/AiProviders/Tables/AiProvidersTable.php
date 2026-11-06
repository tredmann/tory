<?php

namespace App\Filament\Resources\AiProviders\Tables;

use App\Ai\ProviderTester;
use App\Enums\AiVendor;
use App\Models\AiProvider;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    TextColumn::make('model'),
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
                ]),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->paginated(false)
            ->defaultSort('name')
            ->recordClasses(fn (AiProvider $record): ?string => $record->is_active ? 'tory-active-provider' : null)
            ->recordActions([
                Action::make('activate')
                    ->label('Aktivieren')
                    ->hidden(fn (AiProvider $record): bool => $record->is_active)
                    ->action(function (AiProvider $record): void {
                        $record->activate();

                        Notification::make()
                            ->title('Provider aktiviert')
                            ->success()
                            ->send();
                    }),
                Action::make('test')
                    ->label('Testen')
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
                    ]),
                DeleteAction::make()
                    ->label('Löschen')
                    ->disabled(fn (AiProvider $record): bool => $record->is_active)
                    ->tooltip(fn (AiProvider $record): ?string => $record->is_active ? 'Erst einen anderen Provider aktivieren' : null),
            ]);
    }
}
