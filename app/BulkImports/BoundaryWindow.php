<?php

namespace App\BulkImports;

/**
 * A run of consecutive photos shown to the model together. Pair p lies between photo p and p + 1;
 * all bounds are inclusive, and only pairs firstPair to lastPair are decided by this window.
 */
final readonly class BoundaryWindow
{
    public function __construct(
        public int $firstPhoto,
        public int $lastPhoto,
        public int $firstPair,
        public int $lastPair,
    ) {}
}
