<?php

namespace App\Ai;

final readonly class ProviderImportResult
{
    public function __construct(
        public int $created,
        public int $skipped,
        public int $renamed,
    ) {}
}
