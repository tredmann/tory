<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LabelSize: string implements HasLabel
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    public function getLabel(): string
    {
        return match ($this) {
            self::Small => __('Small (approx. 5 × 3 cm)'),
            self::Medium => __('Medium (approx. 7 × 4 cm)'),
            self::Large => __('Large (approx. 10 × 7 cm)'),
        };
    }

    public function fileNamePart(): string
    {
        return match ($this) {
            self::Small => __('small'),
            self::Medium => __('medium'),
            self::Large => __('large'),
        };
    }

    /**
     * How many labels fit next to each other on an A4 sheet.
     *
     * @return positive-int
     */
    public function columns(): int
    {
        return match ($this) {
            self::Small => 4,
            self::Medium, self::Large => 2,
        };
    }
}
