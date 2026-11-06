<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Ai\ProviderModels;
use App\Enums\AiVendor;
use App\Models\AiProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AiProviderForm
{
    public const DEFAULT_OLLAMA_URL = 'http://host.docker.internal:11434';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('vendor')
                ->label('Anbieter')
                ->options(AiVendor::class)
                ->required()
                ->live()
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),
            TextInput::make('api_key')
                ->label('API-Key')
                ->password()
                ->revealable(false)
                ->autocomplete(false)
                ->visible(fn (Get $get): bool => self::vendor($get)?->requiresKey() ?? false)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->placeholder(fn (?AiProvider $record): ?string => $record?->key_hint !== null ? '••••'.$record->key_hint : null)
                ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leer lassen, um den gespeicherten Key zu behalten.' : null)
                ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->live(onBlur: true),
            TextInput::make('url')
                ->label('Adresse')
                ->url()
                ->default(self::DEFAULT_OLLAMA_URL)
                ->visible(fn (Get $get): bool => self::vendor($get) === AiVendor::Ollama)
                ->required()
                ->dehydrateStateUsing(fn (?string $state): string => rtrim(trim((string) $state), '/'))
                ->live(onBlur: true),
            TextInput::make('model')
                ->label('Modell')
                ->required()
                ->maxLength(255)
                ->datalist(fn (Get $get, ?AiProvider $record): array => self::models($get, $record) ?? [])
                ->helperText(fn (Get $get, ?AiProvider $record): ?string => self::models($get, $record) === null
                    ? 'Modelle konnten nicht geladen werden. Du kannst den Namen auch eintippen.'
                    : null),
        ]);
    }

    /**
     * @return list<string>|null
     */
    private static function models(Get $get, ?AiProvider $record): ?array
    {
        $vendor = self::vendor($get) ?? $record?->vendor;

        if ($vendor === null) {
            return [];
        }

        $key = $get('api_key');
        $key = is_string($key) && trim($key) !== '' ? $key : $record?->readableKey();
        $url = $get('url');

        return ProviderModels::for($vendor, $key, is_string($url) ? $url : $record?->url);
    }

    private static function vendor(Get $get): ?AiVendor
    {
        $vendor = $get('vendor');

        return $vendor instanceof AiVendor ? $vendor : AiVendor::tryFrom((string) $vendor);
    }
}
