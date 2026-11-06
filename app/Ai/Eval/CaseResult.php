<?php

namespace App\Ai\Eval;

use App\Ai\ExtractedTexts;

/**
 * The outcome of one case. `$falseHits` holds search words of other cases
 * that find this case's result, as "word → case name".
 */
final readonly class CaseResult
{
    /**
     * @param  list<WordHit>  $hits
     * @param  list<string>  $missingTitleWords
     * @param  list<string>  $forbiddenFound
     * @param  list<string>  $falseHits
     * @param  list<string>  $embeddingNotes  embedding failures that made semantic hits unavailable
     */
    public function __construct(
        public EvalCase $case,
        public ?ExtractedTexts $texts,
        public ?string $error,
        public array $hits,
        public array $missingTitleWords,
        public array $forbiddenFound,
        public array $falseHits,
        public array $embeddingNotes = [],
    ) {}

    public function failed(): bool
    {
        return $this->texts === null;
    }
}
