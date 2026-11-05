<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Wartet',
            self::Running => 'Läuft',
            self::Completed => 'Fertig',
            self::Failed => 'Fehlgeschlagen',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Running => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }
}
