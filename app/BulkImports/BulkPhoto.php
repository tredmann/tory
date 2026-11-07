<?php

namespace App\BulkImports;

use Carbon\CarbonImmutable;

final readonly class BulkPhoto
{
    public function __construct(
        public string $path,
        public ?CarbonImmutable $takenAt,
        public string $name,
    ) {}
}
