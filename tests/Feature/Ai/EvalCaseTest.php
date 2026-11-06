<?php

use App\Ai\Eval\EvalCase;

function casesDirectory(array $definitions): string
{
    $directory = sys_get_temp_dir().'/eval-cases-'.uniqid();
    mkdir($directory);
    copy(resource_path('ai/test-image.png'), $directory.'/foto.png');
    file_put_contents($directory.'/cases.json', json_encode($definitions));

    return $directory;
}

test('a valid case loads with absolute photo paths', function () {
    $cases = EvalCase::loadAll(casesDirectory([
        ['name' => 'Fall', 'photos' => ['foto.png'], 'search_words' => ['a'], 'title_words' => ['b'], 'forbidden_words' => ['c']],
    ]));

    expect($cases)->toHaveCount(1)
        ->and($cases[0]->photos[0])->toEndWith('/foto.png')
        ->and($cases[0]->searchWords)->toBe(['a']);
});

test('a malformed case is reported with its name', function (array $definition, string $message) {
    expect(fn () => EvalCase::loadAll(casesDirectory([$definition])))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'no photos' => [['name' => 'Fall', 'photos' => [], 'search_words' => [], 'title_words' => [], 'forbidden_words' => []], 'Fall'],
    'photos missing' => [['name' => 'Fall', 'search_words' => [], 'title_words' => [], 'forbidden_words' => []], 'Fall'],
    'search words missing' => [['name' => 'Fall', 'photos' => ['foto.png'], 'title_words' => [], 'forbidden_words' => []], 'search_words'],
    'title words not a list' => [['name' => 'Fall', 'photos' => ['foto.png'], 'search_words' => [], 'title_words' => 'x', 'forbidden_words' => []], 'title_words'],
    'forbidden word not a string' => [['name' => 'Fall', 'photos' => ['foto.png'], 'search_words' => [], 'title_words' => [], 'forbidden_words' => [1]], 'forbidden_words'],
]);
