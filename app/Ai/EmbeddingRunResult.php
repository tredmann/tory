<?php

namespace App\Ai;

/**
 * Outcome of one EmbeddingRunner run. `resumeAfterId` is set only when the
 * time budget ran out; a later run continues after that item id.
 */
final readonly class EmbeddingRunResult
{
    public function __construct(
        public int $embedded,
        public int $failures,
        public bool $stoppedEarly,
        public ?string $resumeAfterId = null,
    ) {}
}
