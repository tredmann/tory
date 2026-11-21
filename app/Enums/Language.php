<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Language: string implements HasLabel
{
    case German = 'de';
    case English = 'en';

    public function getLabel(): string
    {
        return match ($this) {
            self::German => 'Deutsch',
            self::English => 'English',
        };
    }
}
