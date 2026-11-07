<?php

namespace App\BulkImports;

/**
 * A photo on a review card, with a temporary signed link to show it.
 */
final readonly class ReviewPhoto
{
    public function __construct(
        public string $path,
        public string $url,
        public bool $discarded,
    ) {}
}
