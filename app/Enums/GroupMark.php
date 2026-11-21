<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GroupMark: string implements HasLabel
{
    case Merged = 'merged';
    case Split = 'split';

    public function getLabel(): string
    {
        return match ($this) {
            self::Merged => __('AI: merged'),
            self::Split => __('AI: split'),
        };
    }
}
