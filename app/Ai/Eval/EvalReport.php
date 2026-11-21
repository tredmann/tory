<?php

namespace App\Ai\Eval;

use App\Enums\Language;

/**
 * Renders eval runs as plain text for the console and the saved file.
 */
final class EvalReport
{
    /**
     * @param  non-empty-list<EvalRun>  $runs
     */
    public function render(string $providerName, Language $language, array $runs): string
    {
        $lines = ["Extraktions-Eval mit {$providerName}", 'Sprache: '.$language->getLabel(), 'Durchläufe: '.count($runs), ''];

        foreach ($runs as $number => $run) {
            $lines[] = '=== Durchlauf '.($number + 1).' ===';
            $lines[] = '';

            foreach ($run->results as $result) {
                array_push($lines, ...$this->renderCase($result));
            }
        }

        $lines[] = '=== Summe ===';
        $lines[] = 'Suchwörter gefunden: '.$this->range($runs, fn (EvalRun $r): int => $r->wordsFound()).' von '.$runs[0]->wordsTotal();
        $lines[] = 'Titelwörter fehlen: '.$this->range($runs, fn (EvalRun $r): int => $r->titleWordsMissing()).' von '.$runs[0]->titleWordsTotal();
        $lines[] = 'Verbotene Wörter: '.$this->range($runs, fn (EvalRun $r): int => $r->forbiddenFound());
        $lines[] = 'Falschtreffer: '.$this->range($runs, fn (EvalRun $r): int => $r->falseHits());
        $lines[] = 'Fehlgeschlagene Fälle: '.$this->range($runs, fn (EvalRun $r): int => $r->failedCases());

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function renderCase(CaseResult $result): array
    {
        $lines = ["--- {$result->case->name} ---"];

        if ($result->failed()) {
            $lines[] = 'FEHLGESCHLAGEN: '.$result->error;
            $lines[] = '';

            return $lines;
        }

        $lines[] = sprintf('%-14s %-9s %-9s %s', 'Suchwort', 'Stichwort', 'Bedeutung', 'Ähnlichkeit');

        foreach ($result->hits as $hit) {
            $lines[] = sprintf(
                '%-14s %-9s %-9s %.2f',
                $hit->word,
                $hit->keyword ? 'ja' : 'nein',
                $hit->semantic ? 'ja' : 'nein',
                $hit->similarity,
            );
        }

        $lines[] = 'Titelwörter fehlen: '.($result->missingTitleWords === [] ? '-' : implode(', ', $result->missingTitleWords));
        $lines[] = 'Verbotene Wörter: '.($result->forbiddenFound === [] ? '-' : implode(', ', $result->forbiddenFound));
        $lines[] = 'Falschtreffer: '.($result->falseHits === [] ? '-' : implode(', ', $result->falseHits));
        foreach ($result->embeddingNotes as $note) {
            $lines[] = 'Hinweis: '.$note;
        }

        $lines[] = 'Titel: '.$result->texts->title;
        $lines[] = 'Beschreibung: '.$result->texts->description;
        $lines[] = 'Suchbegriffe: '.($result->texts->searchTerms === [] ? '-' : implode(', ', $result->texts->searchTerms));
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  non-empty-list<EvalRun>  $runs
     * @param  callable(EvalRun): int  $value
     */
    private function range(array $runs, callable $value): string
    {
        /** @var non-empty-list<int> $values */
        $values = array_map($value, $runs);
        $min = min($values);
        $max = max($values);

        return $min === $max ? (string) $min : "{$min}–{$max}";
    }
}
