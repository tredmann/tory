<?php

namespace App\Filament\Resources\Concerns;

use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;

trait TitledByRecord
{
    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return $this->getRecordTitle();
    }
}
