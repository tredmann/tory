<?php

namespace App\Ai\Eval;

/**
 * Whether a search word finds an item, by keyword and by meaning.
 */
final readonly class WordHit
{
    public function __construct(
        public string $word,
        public bool $keyword,
        public bool $semantic,
        public float $similarity,
    ) {}

    public function found(): bool
    {
        return $this->keyword || $this->semantic;
    }
}
