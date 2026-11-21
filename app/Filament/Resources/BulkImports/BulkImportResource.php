<?php

namespace App\Filament\Resources\BulkImports;

use App\Filament\Resources\BulkImports\Pages\CreateBulkImport;
use App\Filament\Resources\BulkImports\Pages\ViewBulkImport;
use App\Filament\Resources\BulkImports\Schemas\BulkImportForm;
use App\Models\BulkImport;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class BulkImportResource extends Resource
{
    protected static ?string $model = BulkImport::class;

    protected static ?string $slug = 'bulk-imports';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('Bulk import');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Bulk imports');
    }

    public static function getBreadcrumb(): string
    {
        return __('Imports');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return BulkImportForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateBulkImport::route('/create'),
            'view' => ViewBulkImport::route('/{record}'),
        ];
    }
}
