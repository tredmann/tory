<?php

namespace App\Filament\Resources\StorageLocations;

use App\Filament\Resources\StorageLocations\Pages\CreateStorageLocation;
use App\Filament\Resources\StorageLocations\Pages\EditStorageLocation;
use App\Filament\Resources\StorageLocations\Pages\ListStorageLocations;
use App\Filament\Resources\StorageLocations\Pages\ViewStorageLocation;
use App\Filament\Resources\StorageLocations\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\StorageLocations\Schemas\StorageLocationForm;
use App\Filament\Resources\StorageLocations\Schemas\StorageLocationInfolist;
use App\Filament\Resources\StorageLocations\Tables\StorageLocationsTable;
use App\Models\StorageLocation;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StorageLocationResource extends Resource
{
    protected static ?string $model = StorageLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $slug = 'lagerorte';

    protected static ?string $modelLabel = 'Lagerort';

    protected static ?string $pluralModelLabel = 'Lagerorte';

    protected static ?string $navigationLabel = 'Lagerorte';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'label';

    /**
     * Accepts a code in any case. Anything that cannot be a code resolves to no
     * record, so the page answers 404 without querying the database.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $code = strtoupper((string) $key);

        $isCode = strlen($code) === StorageLocation::CODE_LENGTH
            && strspn($code, StorageLocation::CODE_ALPHABET) === StorageLocation::CODE_LENGTH;

        if (! $isCode) {
            return null;
        }

        return parent::resolveRecordRouteBinding($code, $modifyQuery);
    }

    public static function form(Schema $schema): Schema
    {
        return StorageLocationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StorageLocationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StorageLocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorageLocations::route('/'),
            'create' => CreateStorageLocation::route('/create'),
            'view' => ViewStorageLocation::route('/{record}'),
            'edit' => EditStorageLocation::route('/{record}/edit'),
        ];
    }
}
