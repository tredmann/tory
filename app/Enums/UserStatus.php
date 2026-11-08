<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserStatus: string implements HasColor, HasLabel
{
    case Invited = 'invited';
    case Active = 'active';
    case Locked = 'locked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invited => 'Eingeladen',
            self::Active => 'Aktiv',
            self::Locked => 'Gesperrt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invited => 'warning',
            self::Active => 'success',
            self::Locked => 'danger',
        };
    }
}
