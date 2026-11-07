<?php

namespace App\Filament\Resources\Spots;

readonly class SpotLabel
{
    public function __construct(
        public string $code,
        public string $name,
        public string $address,
        public string $qrCode,
    ) {}
}
