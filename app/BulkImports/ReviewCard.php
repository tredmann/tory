<?php

namespace App\BulkImports;

/**
 * One card of the review page: a group with its photos and how many of them remain.
 */
final readonly class ReviewCard
{
    /**
     * @param  list<ReviewPhoto>  $photos
     */
    public function __construct(
        public PhotoGroup $group,
        public int $remaining,
        public bool $overLimit,
        public array $photos,
    ) {}
}
