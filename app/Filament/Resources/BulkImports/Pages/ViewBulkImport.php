<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use App\Filament\Resources\BulkImports\Concerns\SitsBelowImports;
use App\Filament\Resources\Concerns\OmitsPageBreadcrumb;
use App\Filament\Resources\Concerns\StartsBreadcrumbsAtHome;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewBulkImport extends ViewRecord
{
    use OmitsPageBreadcrumb, SitsBelowImports, StartsBreadcrumbsAtHome;

    protected static string $resource = BulkImportResource::class;

    protected static ?string $title = 'Bulkimport';

    public function content(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
