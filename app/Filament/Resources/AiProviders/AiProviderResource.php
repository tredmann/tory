<?php

namespace App\Filament\Resources\AiProviders;

use App\Filament\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\Resources\AiProviders\Schemas\AiProviderForm;
use App\Filament\Resources\AiProviders\Tables\AiProvidersTable;
use App\Models\AiProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiProviderResource extends Resource
{
    protected static ?string $model = AiProvider::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $slug = 'ai-providers';

    protected static ?string $modelLabel = 'Provider';

    protected static ?string $pluralModelLabel = 'Provider';

    protected static ?string $navigationLabel = 'Eigene KI';

    protected static string|UnitEnum|null $navigationGroup = 'Einstellungen';

    protected static ?string $breadcrumb = 'Eigene KI';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AiProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiProvidersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiProviders::route('/'),
        ];
    }
}
