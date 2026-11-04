<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class InventoryExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

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

    public function provider(): string
    {
        return config('ai.inventory.provider');
    }

    public function model(): ?string
    {
        return config('ai.inventory.model');
    }

    public function timeout(): int
    {
        return 180;
    }
}
