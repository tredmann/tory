<?php

use App\Ai\Eval\EvalCase;
use App\Enums\Language;

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
        ['name' => 'Fall', 'photos' => ['foto.png'], 'de' => ['search_words' => ['a'], 'title_words' => ['b'], 'forbidden_words' => ['c']]],
    ]), Language::German);

    expect($cases)->toHaveCount(1)
        ->and($cases[0]->photos[0])->toEndWith('/foto.png')
        ->and($cases[0]->searchWords)->toBe(['a']);
});

test('a malformed case is reported with its name', function (array $definition, string $message) {
    expect(fn () => EvalCase::loadAll(casesDirectory([$definition]), Language::German))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'no photos' => [['name' => 'Fall', 'photos' => [], 'de' => ['search_words' => [], 'title_words' => [], 'forbidden_words' => []]], 'Fall'],
    'photos missing' => [['name' => 'Fall', 'de' => ['search_words' => [], 'title_words' => [], 'forbidden_words' => []]], 'Fall'],
    'search words missing' => [['name' => 'Fall', 'photos' => ['foto.png'], 'de' => ['title_words' => [], 'forbidden_words' => []]], 'search_words'],
    'title words not a list' => [['name' => 'Fall', 'photos' => ['foto.png'], 'de' => ['search_words' => [], 'title_words' => 'x', 'forbidden_words' => []]], 'title_words'],
    'forbidden word not a string' => [['name' => 'Fall', 'photos' => ['foto.png'], 'de' => ['search_words' => [], 'title_words' => [], 'forbidden_words' => [1]]], 'forbidden_words'],
]);

test('the words of the requested language are loaded', function () {
    $directory = casesDirectory([
        [
            'name' => 'Fall',
            'photos' => ['foto.png'],
            'de' => ['search_words' => ['mikrofon'], 'title_words' => ['Mikrofon'], 'forbidden_words' => ['Karton']],
            'en' => ['search_words' => ['microphone'], 'title_words' => ['Microphone'], 'forbidden_words' => ['box']],
        ],
    ]);

    $german = EvalCase::loadAll($directory, Language::German)[0];
    $english = EvalCase::loadAll($directory, Language::English)[0];

    expect($german->searchWords)->toBe(['mikrofon'])
        ->and($german->titleWords)->toBe(['Mikrofon'])
        ->and($german->forbiddenWords)->toBe(['Karton'])
        ->and($english->searchWords)->toBe(['microphone'])
        ->and($english->titleWords)->toBe(['Microphone'])
        ->and($english->forbiddenWords)->toBe(['box']);
});

test('a case without the requested language is reported with its name', function () {
    $directory = casesDirectory([
        ['name' => 'Fall', 'photos' => ['foto.png'], 'de' => ['search_words' => [], 'title_words' => [], 'forbidden_words' => []]],
    ]);

    expect(fn () => EvalCase::loadAll($directory, Language::English))
        ->toThrow(RuntimeException::class, 'Fall');
});
