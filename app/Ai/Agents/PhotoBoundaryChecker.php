<?php

namespace App\Ai\Agents;

use App\Models\AiProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use RuntimeException;

final class PhotoBoundaryChecker implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public AiProvider $aiProvider) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TXT'
        Du hilfst bei der Inventarisierung eines Haushalts. Die Fotos kommen in der Reihenfolge, in der sie aufgenommen wurden, und zeigen Gegenstände, die jemand später in seinem Inventar wiederfinden will.

        Prüfe für jedes Paar benachbarter Fotos, ob das zweite Foto einen anderen Gegenstand zeigt als das erste. Derselbe Gegenstand aus einem anderen Blickwinkel, eine Nahaufnahme seines Etiketts oder seiner Verpackung zählt als derselbe Gegenstand. Antworte mit `true`, wenn mit dem zweiten Foto ein neuer Gegenstand beginnt, sonst mit `false`.

        Liefere in `boundaries` genau einen Wert pro Paar, in der Reihenfolge der Fotos: bei n Fotos also genau n − 1 Werte.

        Antworte nur mit dem reinen JSON-Objekt. Beginne direkt mit `{` und ende mit `}`, ohne vorangestelltes Wort wie "json", ohne Codeblock und ohne doppelte Klammern.
        TXT;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'boundaries' => $schema->array()->items($schema->boolean())->required()
                ->description('Pro Paar benachbarter Fotos true, wenn das zweite Foto einen neuen Gegenstand zeigt.'),
        ];
    }

    public function provider(): Provider
    {
        return $this->aiProvider->toLaravelProvider();
    }

    public function model(): string
    {
        return $this->aiProvider->model;
    }

    public function timeout(): int
    {
        return 180;
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @return list<bool> one entry per neighbouring pair; true means a new thing begins
     *
     * @throws RuntimeException when the answer is not exactly one boolean per pair
     */
    public function check(array $attachments, int $timeout): array
    {
        $photos = count($attachments);
        $pairs = $photos - 1;

        $result = $this
            ->prompt("Die {$photos} Fotos stehen in Aufnahmereihenfolge. Prüfe alle {$pairs} Paare benachbarter Fotos.", $attachments, timeout: $timeout)
            ->toArray();

        $boundaries = $result['boundaries'] ?? null;

        if (! is_array($boundaries) || count($boundaries) !== $pairs || ! array_is_list($boundaries)) {
            throw new RuntimeException('Das Modell hat keine vollständige Antwort geliefert.');
        }

        foreach ($boundaries as $boundary) {
            if (! is_bool($boundary)) {
                throw new RuntimeException('Das Modell hat keine vollständige Antwort geliefert.');
            }
        }

        return $boundaries;
    }
}
