<?php

namespace App\Mcp;

use Carbon\CarbonImmutable;

final readonly class ClaudeConnection
{
    public function __construct(
        public string $clientId,
        public string $name,
        public ?string $host,
        public CarbonImmutable $connectedAt,
    ) {}
}
