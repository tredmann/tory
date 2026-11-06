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

final class InventoryExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public ?AiProvider $aiProvider = null) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'TXT'
        Du hilfst bei der Inventarisierung. Die Fotos zeigen ein einzelnes Objekt, das ins Inventar aufgenommen werden soll.

        Beschreibe ausschließlich, was auf den Fotos tatsächlich sichtbar ist. Erfinde niemals Marke, Modell, Seriennummer, Kapazität oder andere Angaben, die nicht lesbar sind. Wenn Aufdrucke oder Beschriftungen lesbar sind, übernimm sie wörtlich.

        Nenne Farbe, Material, Anschlüsse, Größenhinweise sowie sichtbaren Zustand und Beschädigungen.

        Schreibe alles auf Deutsch. Liefere einen knappen `title` aus wenigen Worten und eine `description` aus einigen sachlichen Sätzen oder einer kompakten Merkmalsliste, ohne Werbesprache.

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
            'title' => $schema->string()->required()->description('Kurzer deutscher Titel des Objekts, wenige Worte.'),
            'description' => $schema->string()->required()->description('Deutsche, sachliche Beschreibung für die spätere Suche.'),
        ];
    }

    public function provider(): Provider|string
    {
        if ($this->aiProvider !== null) {
            return $this->aiProvider->toLaravelProvider();
        }

        return config('ai.inventory.provider') ?: 'anthropic';
    }

    public function model(): ?string
    {
        return $this->aiProvider !== null
            ? $this->aiProvider->model
            : (config('ai.inventory.model') ?: null);
    }

    public function timeout(): int
    {
        return 180;
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @return array{title: string, description: string}
     */
    public function extract(array $attachments, ?int $timeout = null): array
    {
        $result = $this
            ->prompt('Analysiere die Fotos und liefere Titel und Beschreibung.', $attachments, timeout: $timeout)
            ->toArray();

        $title = $result['title'] ?? null;
        $description = $result['description'] ?? null;

        if (! is_string($title) || trim($title) === '' || ! is_string($description) || trim($description) === '') {
            throw new RuntimeException('Das Modell hat keine vollständige Antwort geliefert.');
        }

        return ['title' => $title, 'description' => $description];
    }
}
