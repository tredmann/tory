<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Ai\ProviderModels;
use App\Enums\AiVendor;
use App\Models\AiProvider;
use App\Models\Item;
use Filament\Forms\Components\Checkbox;
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
                ->label(__('Name'))
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('vendor')
                ->label(__('Vendor'))
                ->options(AiVendor::class)
                ->required()
                ->live()
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),
            TextInput::make('api_key')
                ->label(__('API key'))
                ->password()
                ->revealable(false)
                ->autocomplete(false)
                ->visible(fn (Get $get, ?AiProvider $record): bool => self::vendor($get, $record)?->requiresKey() ?? false)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->placeholder(fn (?AiProvider $record): ?string => $record?->key_hint !== null ? '••••'.$record->key_hint : null)
                ->helperText(fn (string $operation): ?string => $operation === 'edit' ? (string) __('Leave empty to keep the saved key.') : null)
                ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->live(onBlur: true),
            TextInput::make('url')
                ->label(__('Address'))
                ->url()
                ->default(self::DEFAULT_OLLAMA_URL)
                ->visible(fn (Get $get, ?AiProvider $record): bool => self::vendor($get, $record) === AiVendor::Ollama)
                ->required()
                ->dehydrateStateUsing(fn (?string $state): string => rtrim(trim((string) $state), '/'))
                ->live(onBlur: true),
            TextInput::make('model')
                ->label(__('Model for image recognition'))
                ->required(fn (Get $get, ?AiProvider $record): bool => ! (self::vendor($get, $record)?->supportsEmbeddings() ?? true)
                    || self::cleaned($get('embedding_model')) === null
                    || $record?->is_active === true)
                ->maxLength(255)
                ->datalist(fn (Get $get, ?AiProvider $record): array => self::models($get, $record) ?? [])
                ->helperText(fn (Get $get, ?AiProvider $record): ?string => self::modelsHint($get, $record))
                ->dehydrateStateUsing(fn (?string $state): ?string => self::cleaned($state))
                ->live(onBlur: true),
            TextInput::make('embedding_model')
                ->label(__('Embedding model'))
                ->visible(fn (Get $get, ?AiProvider $record): bool => self::vendor($get, $record)?->supportsEmbeddings() ?? false)
                ->required(fn (Get $get, ?AiProvider $record): bool => self::cleaned($get('model')) === null
                    || $record?->is_active_for_search === true)
                ->maxLength(255)
                ->datalist(fn (Get $get, ?AiProvider $record): array => self::models($get, $record) ?? [])
                ->helperText(fn (Get $get, ?AiProvider $record): ?string => self::modelsHint($get, $record))
                ->dehydrateStateUsing(fn (?string $state): ?string => self::cleaned($state))
                ->live(onBlur: true),
            TextInput::make('search_min_similarity')
                ->label(__('Minimum similarity'))
                ->numeric()
                ->minValue(0)
                ->maxValue(1)
                ->step(0.01)
                ->default(AiProvider::DEFAULT_SEARCH_MIN_SIMILARITY)
                ->visible(fn (Get $get, ?AiProvider $record): bool => (self::vendor($get, $record)?->supportsEmbeddings() ?? false)
                    && self::cleaned($get('embedding_model')) !== null)
                ->required()
                ->helperText(__('Similarity values differ between models. Adjust the value if search shows unsuitable results or misses matching ones.')),
            Checkbox::make('confirm_search_switch')
                ->label(fn (Get $get, ?AiProvider $record): string => self::switchNotice(
                    (string) $record?->embedding_model,
                    (string) self::cleaned($get('embedding_model')),
                ))
                ->dehydrated(false)
                ->accepted()
                ->visible(function (Get $get, ?AiProvider $record): bool {
                    $embeddingModel = self::cleaned($get('embedding_model'));

                    return $record?->is_active_for_search === true
                        && $embeddingModel !== null
                        && $embeddingModel !== $record->embedding_model;
                }),
        ]);
    }

    /**
     * What a switch of the search provider means, for the activation dialog
     * and the confirmation checkbox of the edit dialog.
     */
    public static function switchNotice(string $oldModel, string $newModel): string
    {
        $itemCount = Item::query()->count();
        $items = $itemCount === 1 ? __('1 item is') : __(':count items are', ['count' => $itemCount]);

        return __('The search switches from :old to :new. :items recalculated; semantic search stays incomplete until it is done. With a paid provider this causes costs.', [
            'old' => $oldModel,
            'new' => $newModel,
            'items' => $items,
        ]);
    }

    private static function modelsHint(Get $get, ?AiProvider $record): ?string
    {
        return self::models($get, $record) === null
            ? (string) __('Models could not be loaded. You can also type the name.')
            : null;
    }

    private static function cleaned(mixed $state): ?string
    {
        $text = trim((string) $state);

        return $text === '' ? null : $text;
    }

    /**
     * @return list<string>|null
     */
    private static function models(Get $get, ?AiProvider $record): ?array
    {
        $vendor = self::vendor($get, $record);

        if ($vendor === null) {
            return [];
        }

        $key = $get('api_key');
        $key = is_string($key) && trim($key) !== '' ? $key : $record?->readableKey();
        $url = $get('url');

        return ProviderModels::for($vendor, $key, is_string($url) ? $url : $record?->url);
    }

    private static function vendor(Get $get, ?AiProvider $record): ?AiVendor
    {
        $vendor = $record !== null ? $record->vendor : $get('vendor');

        return $vendor instanceof AiVendor ? $vendor : AiVendor::tryFrom((string) $vendor);
    }
}
