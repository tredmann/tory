<?php

namespace App\Ai\Eval;

use App\Ai\Agents\InventoryExtractor;
use App\Ai\EmbeddingTarget;
use App\Ai\ExtractedTexts;
use App\Ai\ItemEmbedder;
use App\Ai\ProviderFailure;
use App\Enums\Language;
use App\Models\AiProvider;
use App\Models\Item;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Runs the real extractor over the eval cases and scores the texts against
 * the cases' search words. Writes nothing to the database.
 */
final class ExtractionEval
{
    public function __construct(private readonly ItemEmbedder $embedder) {}

    /**
     * @param  list<EvalCase>  $cases
     */
    public function run(AiProvider $provider, array $cases, Language $language): EvalRun
    {
        $texts = [];
        $errors = [];

        foreach ($cases as $index => $case) {
            try {
                $attachments = array_map(fn (string $path): Image => Image::fromPath($path, mime_content_type($path) ?: null), $case->photos);
                $texts[$index] = InventoryExtractor::make(aiProvider: $provider, language: $language)->extract($attachments);
            } catch (Throwable $e) {
                $errors[$index] = ProviderFailure::from($e, $provider)->getMessage();
            }
        }

        $itemVectors = [];
        $notes = [];
        foreach ($texts as $index => $extracted) {
            try {
                $itemVectors[$index] = $this->embedder->embed($this->embedder->textFor($this->itemFor($extracted)));
            } catch (Throwable $e) {
                $notes[$index][] = 'Der Text konnte nicht eingebettet werden, Bedeutung nicht verfügbar: '.$e->getMessage();
            }
        }

        $wordVectors = [];
        $wordErrors = [];
        foreach ($cases as $case) {
            foreach ($case->searchWords as $word) {
                if (array_key_exists($word, $wordVectors)) {
                    continue;
                }

                try {
                    $wordVectors[$word] = $this->embedder->embed($word);
                } catch (Throwable $e) {
                    $wordVectors[$word] = null;
                    $wordErrors[$word] = "Das Suchwort \"{$word}\" konnte nicht eingebettet werden, nur Stichwort: ".$e->getMessage();
                }
            }
        }

        $results = [];

        foreach ($cases as $index => $case) {
            $extracted = $texts[$index] ?? null;

            if ($extracted === null) {
                $results[] = new CaseResult(
                    $case,
                    null,
                    $errors[$index] ?? null,
                    array_map(fn (string $word): WordHit => new WordHit($word, false, false, 0.0), $case->searchWords),
                    $case->titleWords,
                    [],
                    [],
                    [],
                );

                continue;
            }

            $hits = array_map(
                fn (string $word): WordHit => $this->score($word, $extracted, $wordVectors[$word], $itemVectors[$index] ?? null),
                $case->searchWords,
            );

            $falseHits = [];
            foreach ($cases as $otherIndex => $other) {
                if ($otherIndex === $index) {
                    continue;
                }

                foreach ($other->searchWords as $word) {
                    if (in_array(mb_strtolower($word), array_map('mb_strtolower', $case->searchWords), true)) {
                        continue;
                    }

                    if ($this->score($word, $extracted, $wordVectors[$word], $itemVectors[$index] ?? null)->found()) {
                        $falseHits[] = "{$word} → {$case->name}";
                    }
                }
            }

            $results[] = new CaseResult(
                $case,
                $extracted,
                null,
                $hits,
                array_values(array_filter(
                    $case->titleWords,
                    fn (string $word): bool => mb_stripos($extracted->title, $word) === false,
                )),
                array_values(array_filter(
                    $case->forbiddenWords,
                    fn (string $word): bool => $this->containsWholeWord($extracted->title."\n".$extracted->description, $word),
                )),
                array_values(array_unique($falseHits)),
                array_merge(
                    $notes[$index] ?? [],
                    array_values(array_intersect_key($wordErrors, array_flip($case->searchWords))),
                ),
            );
        }

        return new EvalRun($results);
    }

    /**
     * @param  list<float>|null  $wordVector  null when embedding failed
     * @param  list<float>|null  $itemVector  null when embedding failed
     */
    private function score(string $word, ExtractedTexts $texts, ?array $wordVector, ?array $itemVector): WordHit
    {
        $keyword = mb_stripos($texts->title, $word) !== false || mb_stripos($texts->description, $word) !== false;

        foreach ($texts->searchTerms as $term) {
            $keyword = $keyword || mb_stripos($term, $word) !== false;
        }

        if ($wordVector === null || $itemVector === null) {
            return new WordHit($word, $keyword, false, 0.0);
        }

        $similarity = $this->cosine($wordVector, $itemVector);

        return new WordHit($word, $keyword, $similarity >= EmbeddingTarget::current()->minSimilarity, $similarity);
    }

    private function containsWholeWord(string $text, string $word): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value ** 2;
            $normB += $b[$i] ** 2;
        }

        return $normA > 0 && $normB > 0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }

    private function itemFor(ExtractedTexts $texts): Item
    {
        return new Item([
            'title' => $texts->title,
            'description' => $texts->description,
            'search_terms' => $texts->searchTerms,
        ]);
    }
}
