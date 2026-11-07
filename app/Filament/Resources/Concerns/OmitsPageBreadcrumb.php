<?php

namespace App\Filament\Resources\Concerns;

use Filament\Support\Components\Attributes\Unexposed;

trait OmitsPageBreadcrumb
{
    /**
     * @return array<string>
     */
    #[Unexposed]
    public function getBreadcrumbs(): array
    {
        return array_slice(parent::getBreadcrumbs(), 0, -1, preserve_keys: true);
    }
}
