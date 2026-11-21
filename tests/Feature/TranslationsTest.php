<?php

use Symfony\Component\Finder\Finder;

/**
 * Keys of PHP translation files such as `auth.failed`; they are not checked.
 */
function isPhpFileTranslationKey(string $key): bool
{
    return preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $key) === 1;
}

/**
 * Every `__('…')` or `__("…")` literal in the source, unescaped like PHP does.
 *
 * @return list<string>
 */
function translationKeysIn(string $source): array
{
    $pattern = '/(?<![\w$])(?<!->)(?<!::)(?:__|trans_choice)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s';
    $keys = [];

    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $key = ($match[2] ?? '') !== ''
            ? strtr($match[2], ['\\"' => '"', '\\\\' => '\\', '\\$' => '$', '\\n' => "\n"])
            : strtr($match[1], ["\\'" => "'", '\\\\' => '\\']);

        if (! isPhpFileTranslationKey($key)) {
            $keys[$key] = true;
        }
    }

    return array_map(strval(...), array_keys($keys));
}

/**
 * @return list<string>
 */
function translationKeysInCode(): array
{
    $files = Finder::create()
        ->files()
        ->in([base_path('app'), base_path('resources/views')])
        ->name(['*.php']);

    $keys = [];

    foreach ($files as $file) {
        foreach (translationKeysIn($file->getContents()) as $key) {
            $keys[$key] = true;
        }
    }

    return array_map(strval(...), array_keys($keys));
}

/**
 * @return list<string>
 */
function germanTranslationKeys(): array
{
    $translations = json_decode(file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter(
        array_map(strval(...), array_keys($translations)),
        fn (string $key): bool => ! isPhpFileTranslationKey($key),
    ));
}

it('extracts translation literals from source', function () {
    $source = <<<'SRC'
    <?php
    $a = __('Single quoted');
    $b = __("Double quoted");
    $c = __('Don\'t stop');
    $d = __('Hello :name');
    $e = __('Er sagte „Hallo“ und “Hi”');
    $f = __(
        'Multi line :max',
        ['max' => 3],
    );
    $g = __("Say \"hi\"");
    $h = __('Saved.');
    $i = __('auth.failed');
    $j = $obj->__('Method call');
    $k = $__('Variable call');
    $l = Foo::__('Static call');
    $m = trans_choice(':count apple|:count apples', 3);
    SRC;

    $blade = "<p>{{ __('Blade text') }}</p><b>__('Bare after tag')</b>";

    expect(translationKeysIn($source.$blade))->toBe([
        'Single quoted',
        'Double quoted',
        "Don't stop",
        'Hello :name',
        'Er sagte „Hallo“ und “Hi”',
        'Multi line :max',
        'Say "hi"',
        'Saved.',
        ':count apple|:count apples',
        'Blade text',
        'Bare after tag',
    ]);
});

it('has a German translation for every translated string', function () {
    $missing = array_values(array_diff(translationKeysInCode(), germanTranslationKeys()));

    expect($missing)->toBe([]);
});

it('uses every German translation', function () {
    $unused = array_values(array_diff(germanTranslationKeys(), translationKeysInCode()));

    expect($unused)->toBe([]);
});

it('renders placeholders in both languages', function () {
    app()->setLocale('de');
    expect(__('At most :max photos.', ['max' => 20]))->toBe('Höchstens 20 Fotos.');

    app()->setLocale('en');
    expect(__('At most :max photos.', ['max' => 20]))->toBe('At most 20 photos.');
});
