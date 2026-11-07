<?php

namespace App\Filament\Resources\Spots;

use App\Filament\Resources\Spots\Pages\CreateSpot;
use App\Filament\Resources\Spots\Pages\EditSpot;
use App\Filament\Resources\Spots\Pages\ListSpots;
use App\Filament\Resources\Spots\Pages\ViewSpot;
use App\Filament\Resources\Spots\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Spots\Schemas\SpotForm;
use App\Filament\Resources\Spots\Schemas\SpotInfolist;
use App\Filament\Resources\Spots\Tables\SpotsTable;
use App\Models\Spot;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SpotResource extends Resource
{
    protected static ?string $model = Spot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $slug = 'spots';

    protected static ?string $modelLabel = 'Lagerplatz';

    protected static ?string $pluralModelLabel = 'Lagerplätze';

    protected static ?string $navigationLabel = 'Lagerplätze';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'label';

    /**
     * Accepts a code in any case. Anything that cannot be a code resolves to no
     * record, so the page answers 404 without querying the database.
     */
    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $code = Spot::normalizeCode((string) $key);

        if ($code === null) {
            return null;
        }

        return parent::resolveRecordRouteBinding($code, $modifyQuery);
    }

    public static function form(Schema $schema): Schema
    {
        return SpotForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SpotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpotsTable::configure($table);
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
            'index' => ListSpots::route('/'),
            'create' => CreateSpot::route('/create'),
            'view' => ViewSpot::route('/{record}'),
            'edit' => EditSpot::route('/{record}/edit'),
        ];
    }
}
