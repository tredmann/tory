<?php

namespace App\Filament\Resources\BulkImports\Concerns;

use App\Filament\Resources\Imports\ImportResource;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk imports have no list of their own: the resource breadcrumb and "Abbrechen" lead to the import list.
 */
trait SitsBelowImports
{
    /**
     * @param  array<mixed>  $parameters
     */
    #[Unexposed]
    public function getResourceUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = true): string
    {
        if (blank($name) || $name === 'index') {
            return ImportResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant);
        }

        return parent::getResourceUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }
}
