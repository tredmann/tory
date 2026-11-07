<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BulkImportStatus: string implements HasColor, HasLabel
{
    case Grouping = 'grouping';
    case Ready = 'ready';

    public function getLabel(): string
    {
        return match ($this) {
            self::Grouping => 'Wird gruppiert',
            self::Ready => 'Bereit zur Prüfung',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Grouping => 'warning',
            self::Ready => 'info',
        };
    }
}
