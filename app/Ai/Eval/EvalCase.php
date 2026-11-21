<?php

namespace App\Ai\Eval;

use App\Enums\Language;
use RuntimeException;

/**
 * One item to extract in the eval: its photos and what the result must,
 * and must not, say.
 */
final readonly class EvalCase
{
    /**
     * @param  list<string>  $photos  absolute paths
     * @param  list<string>  $searchWords  words a user would type to find the item
     * @param  list<string>  $titleWords  words the title must contain
     * @param  list<string>  $forbiddenWords  words or phrases title and description must not contain
     */
    public function __construct(
        public string $name,
        public array $photos,
        public array $searchWords,
        public array $titleWords,
        public array $forbiddenWords,
    ) {}

    /**
     * Loads `cases.json` of the directory; photo paths are relative to it and
     * the word lists are those of the content language.
     *
     * @return list<EvalCase>
     *
     * @throws RuntimeException when the file is unreadable or a photo is missing
     */
    public static function loadAll(string $directory, Language $language): array
    {
        $file = rtrim($directory, '/').'/cases.json';
        $json = is_file($file) ? file_get_contents($file) : false;
        $definitions = $json === false ? null : json_decode($json, true);

        if (! is_array($definitions)) {
            throw new RuntimeException("Die Testfälle in {$file} sind nicht lesbar.");
        }

        $cases = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['name'] ?? null)) {
                throw new RuntimeException("Die Testfälle in {$file} sind nicht lesbar.");
            }

            $name = $definition['name'];
            $photos = [];

            foreach (self::stringList($definition, 'photos', $name) as $photo) {
                $path = rtrim($directory, '/').'/'.$photo;

                if (! is_file($path)) {
                    throw new RuntimeException("Das Foto {$path} fehlt.");
                }

                $photos[] = $path;
            }

            if ($photos === []) {
                throw new RuntimeException("Der Testfall \"{$name}\" hat keine Fotos.");
            }

            $words = $definition[$language->value] ?? null;

            if (! is_array($words)) {
                throw new RuntimeException("Der Testfall \"{$name}\" hat keine Wörter für die Sprache {$language->value}.");
            }

            $cases[] = new self(
                $name,
                $photos,
                self::stringList($words, 'search_words', $name),
                self::stringList($words, 'title_words', $name),
                self::stringList($words, 'forbidden_words', $name),
            );
        }

        return $cases;
    }

    /**
     * @param  array<mixed>  $definition  the word lists of one language
     * @return list<string>
     *
     * @throws RuntimeException when the field is missing or not a list of strings
     */
    private static function stringList(array $definition, string $field, string $name): array
    {
        $value = $definition[$field] ?? null;

        if (! is_array($value) || ! array_is_list($value) || array_filter($value, fn (mixed $entry): bool => ! is_string($entry)) !== []) {
            throw new RuntimeException("Im Testfall \"{$name}\" fehlt das Feld {$field} oder es ist keine Liste von Texten.");
        }

        /** @var list<string> $value */
        return $value;
    }
}
