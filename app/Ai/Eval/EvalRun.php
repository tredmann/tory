<?php

namespace App\Ai\Eval;

/**
 * All case results of one pass over the cases, with their totals.
 */
final readonly class EvalRun
{
    /**
     * @param  list<CaseResult>  $results
     */
    public function __construct(public array $results) {}

    public function wordsTotal(): int
    {
        return array_sum(array_map(fn (CaseResult $r): int => count($r->hits), $this->results));
    }

    public function wordsFound(): int
    {
        return array_sum(array_map(
            fn (CaseResult $r): int => count(array_filter($r->hits, fn (WordHit $h): bool => $h->found())),
            $this->results,
        ));
    }

    public function titleWordsTotal(): int
    {
        return array_sum(array_map(fn (CaseResult $r): int => count($r->case->titleWords), $this->results));
    }

    public function titleWordsMissing(): int
    {
        return array_sum(array_map(fn (CaseResult $r): int => count($r->missingTitleWords), $this->results));
    }

    public function forbiddenFound(): int
    {
        return array_sum(array_map(fn (CaseResult $r): int => count($r->forbiddenFound), $this->results));
    }

    public function falseHits(): int
    {
        return array_sum(array_map(fn (CaseResult $r): int => count($r->falseHits), $this->results));
    }

    public function failedCases(): int
    {
        return count(array_filter($this->results, fn (CaseResult $r): bool => $r->failed()));
    }

    public function allFailed(): bool
    {
        return $this->results !== [] && $this->failedCases() === count($this->results);
    }
}
