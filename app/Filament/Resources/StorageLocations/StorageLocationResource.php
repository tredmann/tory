<?php

namespace App\Filament\Resources\StorageLocations;

use App\Filament\Resources\Concerns\ResolvesNumericRecordKeys;
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
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StorageLocationResource extends Resource
{
    use ResolvesNumericRecordKeys;

    protected static ?string $model = StorageLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $slug = 'lagerorte';

    protected static ?string $modelLabel = 'Lagerort';

    protected static ?string $pluralModelLabel = 'Lagerorte';

    protected static ?string $navigationLabel = 'Lagerorte';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'label';

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
