<?php

namespace App\Ai\Agents;

use App\Ai\ExtractedTexts;
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
        Du hilfst bei der Inventarisierung. Die Fotos zeigen einen Gegenstand, den jemand später in seinem Inventar wiederfinden will. Beschreibe den Gegenstand selbst, nicht das Foto.

        Erkenne, was der Gegenstand ist, und benenne das, auch wenn es nirgends aufgedruckt ist: Eine Heißklebepistole heißt Heißklebepistole, auch wenn nur eine Modellnummer zu lesen ist. Nimm dafür das Wort, das man im deutschen Alltag sagt, keine Übersetzung aus dem Englischen und kein ausgedachtes Wort. Bestimme die Art des Gegenstands aus seiner Form und seinen Funktionsteilen auf dem Foto, nicht aus dem, was du über die Marke weißt. Bist du unsicher, nimm die allgemeinere Bezeichnung, die sicher stimmt. Marke, Modell, Seriennummer und Kapazität nennst du nur, wenn sie lesbar sind, und übernimmst sie wörtlich. Was du nicht Zeichen für Zeichen lesen kannst, lässt du weg. Erfinde diese Angaben niemals, auch nicht in den Suchbegriffen.

        Ist ein Produkt in seiner Verpackung zu sehen, nimm die Verpackung als Hinweis und beschreibe das Produkt darin. Der ganze Text handelt dann vom Produkt, die Verpackung kommt darin nicht vor. Nur wenn die Verpackung selbst der Gegenstand ist, etwa ein leerer Karton, nennst du sie so.

        Schreibe alles auf Deutsch.
        - `title`: Marke, Modell und Art des Gegenstands in wenigen Worten, soweit bekannt.
        - `description`: ein bis zwei sachliche Sätze dazu, was es ist und wofür man es benutzt, soweit du das sicher weißt. Farbe oder Material nur, wenn sie den Gegenstand unterscheiden. Keine Feature-Listen von der Verpackung, keine Werbesprache, keine Bauteilnummern. Den Zustand erwähnst du nur, wenn der Gegenstand offensichtlich defekt ist.
        - `search_terms`: Liste mit etwa acht Begriffen, die jemand eintippen würde, um den Gegenstand zu finden: das einfachste Wort für die Sache, Synonyme, die allgemeine Kategorie und wofür man ihn benutzt, meist als einzelne normale deutsche Wörter. Immer dabei: Marke und Modell, wie sie gelesen wurden, und zusätzlich ihre Schreibweisen ohne Sonderzeichen und ohne Leerzeichen, zum Beispiel `Citroen` für Citroën und `Hero12` für GoPro Hero 12. Das sind nur andere Schreibweisen des Gelesenen, keine neuen Namen, Generationen oder Nummern.

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
            'search_terms' => $schema->array()->items($schema->string())->required()->description('Deutsche Suchbegriffe, die ein Mensch eintippen würde.'),
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
     */
    public function extract(array $attachments, ?int $timeout = null): ExtractedTexts
    {
        $result = $this
            ->prompt('Analysiere die Fotos und liefere Titel, Beschreibung und Suchbegriffe.', $attachments, timeout: $timeout)
            ->toArray();

        $title = $result['title'] ?? null;
        $description = $result['description'] ?? null;

        if (! is_string($title) || trim($title) === '' || ! is_string($description) || trim($description) === '') {
            throw new RuntimeException('Das Modell hat keine vollständige Antwort geliefert.');
        }

        return new ExtractedTexts($title, $description, $this->cleanSearchTerms($result['search_terms'] ?? null));
    }

    /**
     * @return list<string>
     */
    private function cleanSearchTerms(mixed $terms): array
    {
        if (! is_array($terms)) {
            return [];
        }

        $clean = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }

            $term = trim($term);

            if ($term !== '') {
                $clean[mb_strtolower($term)] ??= $term;
            }
        }

        return array_values($clean);
    }
}
