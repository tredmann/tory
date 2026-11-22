<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\Eval\EvalCase;
use App\Ai\Eval\EvalRun;
use App\Ai\Eval\ExtractionEval;
use App\Enums\Language;
use App\Models\AiProvider;
use App\Models\Import;
use App\Models\Item;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * @param  list<string>  $searchWords
 * @param  list<string>  $titleWords
 * @param  list<string>  $forbiddenWords
 */
function evalCase(string $name = 'Fall', array $searchWords = [], array $titleWords = [], array $forbiddenWords = []): EvalCase
{
    return new EvalCase($name, [resource_path('ai/test-image.png')], $searchWords, $titleWords, $forbiddenWords);
}

/** Every text embeds as axis 0 unless its text is listed. */
function fakeVectors(array $byInput = []): void
{
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
        fn (string $input): array => $byInput[$input] ?? axis(0),
        $prompt->inputs,
    ));
}

function runEval(array $cases, ?AiProvider $provider = null): EvalRun
{
    return app(ExtractionEval::class)->run($provider ?? AiProvider::factory()->create(), $cases, Language::German);
}

beforeEach(function (): void {
    AiProvider::factory()->activeForSearch()->create();
});

test('keyword hit is a case-insensitive substring in title, description or terms', function (): void {
    fakeVectors(['klammer' => axis(5), 'rode' => axis(6)]);
    InventoryExtractor::fake([
        ['title' => 'Hefter', 'description' => 'Mit Heftklammern.', 'search_terms' => ['Rode']],
    ]);

    $hits = collect(runEval([evalCase(searchWords: ['klammer', 'rode'])])->results[0]->hits)->keyBy('word');

    expect($hits['klammer']->keyword)->toBeTrue()
        ->and($hits['rode']->keyword)->toBeTrue();
});

test('a word that appears nowhere is no keyword hit', function (): void {
    fakeVectors(['rode' => axis(6)]);
    InventoryExtractor::fake([['title' => 'RØDE Mikrofon', 'description' => 'Schwarz.']]);

    $hit = runEval([evalCase(searchWords: ['rode'])])->results[0]->hits[0];

    expect($hit->keyword)->toBeFalse()->and($hit->found())->toBeFalse();
});

test('semantic hit uses the configured threshold', function (): void {
    AiProvider::query()->update(['search_min_similarity' => 0.6]);
    fakeVectors(['nah' => [3.0, 4.0, ...array_fill(0, 1022, 0.0)], 'fern' => [1.0, 2.0, ...array_fill(0, 1022, 0.0)]]);
    InventoryExtractor::fake([['title' => 'Etwas', 'description' => 'Anderes.']]);

    $hits = collect(runEval([evalCase(searchWords: ['nah', 'fern'])])->results[0]->hits)->keyBy('word');

    expect($hits['nah']->semantic)->toBeTrue()
        ->and($hits['nah']->similarity)->toBe(0.6)
        ->and($hits['fern']->semantic)->toBeFalse();
});

test('semantic hit uses the threshold of the active search provider', function (): void {
    AiProvider::query()->update(['search_min_similarity' => 0.9]);
    fakeVectors(['nah' => [3.0, 4.0, ...array_fill(0, 1022, 0.0)]]);
    InventoryExtractor::fake([['title' => 'Etwas', 'description' => 'Anderes.']]);

    $hit = runEval([evalCase(searchWords: ['nah'])])->results[0]->hits[0];

    expect($hit->similarity)->toBe(0.6)->and($hit->semantic)->toBeFalse();
});

test('a missing title word is reported', function (): void {
    fakeVectors();
    InventoryExtractor::fake([['title' => 'Mikrofon', 'description' => 'x']]);

    $result = runEval([evalCase(titleWords: ['Mikrofon', 'USV'])])->results[0];

    expect($result->missingTitleWords)->toBe(['USV']);
});

test('forbidden words match whole words in title and description only', function (): void {
    fakeVectors();
    InventoryExtractor::fake([
        ['title' => 'Einplatinencomputer', 'description' => 'Grüne Platine, best quality.', 'search_terms' => ['Verpackung']],
    ]);

    $result = runEval([evalCase(forbiddenWords: ['Platine', 'Best Quality', 'Verpackung', 'Computer'])])->results[0];

    expect($result->forbiddenFound)->toBe(['Platine', 'Best Quality']);
});

test('Platine is not hit by Einplatinencomputer', function (): void {
    fakeVectors();
    InventoryExtractor::fake([['title' => 'Einplatinencomputer', 'description' => 'Klein.']]);

    expect(runEval([evalCase(forbiddenWords: ['Platine'])])->results[0]->forbiddenFound)->toBe([]);
});

test('reports a false hit on another case', function (): void {
    fakeVectors(['hefter' => axis(7), 'mikrofon' => axis(8)]);
    InventoryExtractor::fake([
        ['title' => 'RØDE Wireless GO', 'description' => 'Ein Hefter?'],
        ['title' => 'Hefter', 'description' => 'Metall.'],
    ]);

    $run = runEval([
        evalCase('RØDE Wireless GO', ['mikrofon']),
        evalCase('Soennecken 3155', ['hefter']),
    ]);

    expect($run->results[0]->falseHits)->toBe(['hefter → RØDE Wireless GO'])
        ->and($run->results[1]->falseHits)->toBe([])
        ->and($run->falseHits())->toBe(1);
});

test('a failing case is reported and the others still run', function (): void {
    fakeVectors();
    $calls = 0;
    InventoryExtractor::fake(function () use (&$calls): array {
        return ++$calls === 1
            ? throw new RuntimeException('Modell kaputt')
            : ['title' => 'Mikrofon', 'description' => 'x'];
    });

    $run = runEval([evalCase('A', ['mikrofon']), evalCase('B', ['mikrofon'])]);

    expect($run->results[0]->failed())->toBeTrue()
        ->and($run->results[0]->error)->toContain('Modell kaputt')
        ->and($run->results[0]->hits[0]->found())->toBeFalse()
        ->and($run->results[1]->failed())->toBeFalse()
        ->and($run->failedCases())->toBe(1)
        ->and($run->allFailed())->toBeFalse();
});

test('a rejected key never reaches the result', function (): void {
    fakeVectors();
    $provider = AiProvider::factory()->create(['api_key' => 'sk-secret-key-1234567890']);
    InventoryExtractor::fake(fn (): never => throw new RuntimeException('Invalid key sk-secret-key-1234567890'));

    $result = runEval([evalCase()], $provider)->results[0];

    expect($result->error)->not->toContain('sk-secret-key-1234567890');
});

test('writes nothing', function (): void {
    Queue::fake();
    fakeVectors();
    InventoryExtractor::fake([['title' => 'Mikrofon', 'description' => 'x']]);

    runEval([evalCase()]);

    expect(Item::query()->count())->toBe(0)->and(Import::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('uses the given provider, not the active one', function (): void {
    fakeVectors();
    AiProvider::factory()->active()->create();
    $given = AiProvider::factory()->create();
    InventoryExtractor::fake([['title' => 'Mikrofon', 'description' => 'x']]);

    runEval([evalCase()], $given);

    InventoryExtractor::assertPrompted(fn ($prompt): bool => $prompt->agent->aiProvider?->is($given) === true);
});

test('a failing item text embedding is noted on the case and keyword hits remain', function (): void {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => str_contains($prompt->inputs[0], 'Mikrofon')
        ? throw new RuntimeException('bge-m3 nicht geladen')
        : [axis(0)]);
    InventoryExtractor::fake([['title' => 'Mikrofon', 'description' => 'x']]);

    $result = runEval([evalCase(searchWords: ['mikrofon', 'rode'])])->results[0];

    expect($result->failed())->toBeFalse()
        ->and($result->hits[0]->keyword)->toBeTrue()
        ->and($result->hits[1]->found())->toBeFalse()
        ->and($result->hits[1]->similarity)->toBe(0.0)
        ->and(implode(' ', $result->embeddingNotes))->toContain('bge-m3 nicht geladen');
});

test('a failing word embedding gives a keyword-only hit with a note', function (): void {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => $prompt->inputs[0] === 'rode'
        ? throw new RuntimeException('Zeitüberschreitung')
        : [axis(0)]);
    InventoryExtractor::fake([['title' => 'RØDE', 'description' => 'Rode Mikrofon']]);

    $result = runEval([evalCase(searchWords: ['rode', 'mikrofon'])])->results[0];

    expect($result->hits[0]->keyword)->toBeTrue()
        ->and($result->hits[0]->semantic)->toBeFalse()
        ->and($result->hits[0]->similarity)->toBe(0.0)
        ->and($result->hits[1]->keyword)->toBeTrue()
        ->and(implode(' ', $result->embeddingNotes))->toContain('rode')->toContain('Zeitüberschreitung');
});

test('the photo is sent with the mime type of its file', function (): void {
    fakeVectors();
    InventoryExtractor::fake([['title' => 'Etwas', 'description' => 'Anderes.']]);

    runEval([evalCase()]);

    InventoryExtractor::assertPrompted(fn ($prompt): bool => $prompt->attachments->first()->mimeType() === 'image/png');
});

test('without a search provider the eval reports keyword hits only', function (): void {
    AiProvider::query()->update(['is_active_for_search' => false]);
    Embeddings::fake()->preventStrayEmbeddings();
    InventoryExtractor::fake([['title' => 'Hefter', 'description' => 'Mit Heftklammern.']]);

    $result = runEval([evalCase(searchWords: ['klammer'])])->results[0];

    expect($result->hits[0]->keyword)->toBeTrue()
        ->and($result->hits[0]->semantic)->toBeFalse()
        ->and(implode(' ', $result->embeddingNotes))->toContain('No AI provider is active for search.');
});
