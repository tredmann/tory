<?php

use Symfony\Component\Finder\Finder;

/**
 * Every `__('…')` or `__("…")` literal in app/ and resources/views/, unescaped
 * like PHP does. Keys with a dot and no space (`auth.failed`) belong to PHP
 * translation files and are skipped.
 *
 * @return list<string>
 */
function translationKeysInCode(): array
{
    $files = Finder::create()
        ->files()
        ->in([base_path('app'), base_path('resources/views')])
        ->name(['*.php']);

    $pattern = '/(?<![\w>:$])__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s';
    $keys = [];

    foreach ($files as $file) {
        preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = ($match[2] ?? '') !== ''
                ? strtr($match[2], ['\\"' => '"', '\\\\' => '\\', '\\$' => '$', '\\n' => "\n"])
                : strtr($match[1], ["\\'" => "'", '\\\\' => '\\']);

            if (str_contains($key, '.') && ! str_contains($key, ' ')) {
                continue;
            }

            $keys[$key] = true;
        }
    }

    return array_map('strval', array_keys($keys));
}

/**
 * @return list<string>
 */
function germanTranslationKeys(): array
{
    $translations = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    return array_map('strval', array_keys($translations));
}

it('has a German translation for every translated string', function () {
    $missing = array_values(array_diff(translationKeysInCode(), germanTranslationKeys()));

    expect($missing)->toBe([]);
});

it('uses every German translation', function () {
    $unused = array_values(array_diff(germanTranslationKeys(), translationKeysInCode()));

    expect($unused)->toBe([]);
});
