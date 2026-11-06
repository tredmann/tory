<?php

namespace App\Ai;

/**
 * The texts the extractor derived from an item's photos.
 */
final readonly class ExtractedTexts
{
    /**
     * @param  list<string>  $searchTerms
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $searchTerms,
    ) {}
}
