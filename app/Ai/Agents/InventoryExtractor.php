<?php

namespace App\Ai\Agents;

use App\Ai\ExtractedTexts;
use App\Enums\Language;
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

    public function __construct(
        public AiProvider $aiProvider,
        public Language $language = Language::German,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return match ($this->language) {
            Language::German => <<<'TXT'
            Du hilfst bei der Inventarisierung. Die Fotos zeigen einen Gegenstand, den jemand später in seinem Inventar wiederfinden will. Beschreibe den Gegenstand selbst, nicht das Foto.

            Erkenne, was der Gegenstand ist, und benenne das, auch wenn es nirgends aufgedruckt ist: Eine Heißklebepistole heißt Heißklebepistole, auch wenn nur eine Modellnummer zu lesen ist. Nimm dafür das Wort, das man im deutschen Alltag sagt, keine Übersetzung aus dem Englischen und kein ausgedachtes Wort. Bestimme die Art des Gegenstands aus seiner Form und seinen Funktionsteilen auf dem Foto, nicht aus dem, was du über die Marke weißt. Bist du unsicher, nimm die allgemeinere Bezeichnung, die sicher stimmt. Marke, Modell, Seriennummer und Kapazität nennst du nur, wenn sie lesbar sind, und übernimmst sie wörtlich. Was du nicht Zeichen für Zeichen lesen kannst, lässt du weg. Erfinde diese Angaben niemals, auch nicht in den Suchbegriffen.

            Ist ein Produkt in seiner Verpackung zu sehen, nimm die Verpackung als Hinweis und beschreibe das Produkt darin. Der ganze Text handelt dann vom Produkt, die Verpackung kommt darin nicht vor. Nur wenn die Verpackung selbst der Gegenstand ist, etwa ein leerer Karton, nennst du sie so.

            Schreibe alles auf Deutsch.
            - `title`: Marke, Modell und Art des Gegenstands in wenigen Worten, soweit bekannt.
            - `description`: ein bis zwei sachliche Sätze dazu, was es ist und wofür man es benutzt, soweit du das sicher weißt. Farbe oder Material nur, wenn sie den Gegenstand unterscheiden. Keine Feature-Listen von der Verpackung, keine Werbesprache, keine Bauteilnummern. Den Zustand erwähnst du nur, wenn der Gegenstand offensichtlich defekt ist.
            - `search_terms`: Liste mit etwa acht Begriffen, die jemand eintippen würde, um den Gegenstand zu finden: das einfachste Wort für die Sache, Synonyme, die allgemeine Kategorie und wofür man ihn benutzt, meist als einzelne normale deutsche Wörter. Immer dabei: Marke und Modell, wie sie gelesen wurden, und zusätzlich ihre Schreibweisen ohne Sonderzeichen und ohne Leerzeichen, zum Beispiel `Citroen` für Citroën und `Hero12` für GoPro Hero 12. Das sind nur andere Schreibweisen des Gelesenen, keine neuen Namen, Generationen oder Nummern.

            Antworte nur mit dem reinen JSON-Objekt. Beginne direkt mit `{` und ende mit `}`, ohne vorangestelltes Wort wie "json", ohne Codeblock und ohne doppelte Klammern.
            TXT,
            Language::English => <<<'TXT'
            You help with taking inventory. The photos show an object that someone wants to find again in their inventory later. Describe the object itself, not the photo.

            Recognize what the object is and name it, even if that is printed nowhere: a hot glue gun is called a hot glue gun, even if only a model number can be read. Use the word people say in everyday English, not a translation from another language and not an invented word. Determine the kind of object from its shape and its functional parts in the photo, not from what you know about the brand. If you are unsure, use the more general name that is certainly correct. Mention brand, model, serial number and capacity only if they are legible, and copy them literally. Leave out anything you cannot read character by character. Never invent these details, not even in the search terms.

            If a product is shown in its packaging, take the packaging as a clue and describe the product inside it. The whole text is then about the product; the packaging does not appear in it. Only if the packaging itself is the object, such as an empty box, do you call it that.

            Write everything in English.
            - `title`: brand, model and kind of object in a few words, as far as known.
            - `description`: one or two factual sentences on what it is and what it is used for, as far as you know for certain. Color or material only if they set the object apart. No feature lists from the packaging, no marketing language, no part numbers. Mention the condition only if the object is obviously broken.
            - `search_terms`: a list of about eight terms someone would type to find the object: the simplest word for the thing, synonyms, the general category and what it is used for, mostly as single plain English words. Always include brand and model as read, plus their spellings without special characters and without spaces, for example `Citroen` for Citroën and `Hero12` for GoPro Hero 12. These are only other spellings of what was read, not new names, generations or numbers.

            Reply only with the bare JSON object. Start directly with `{` and end with `}`, without a leading word like "json", without a code block and without double braces.
            TXT,
        };
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        [$title, $description, $searchTerms] = match ($this->language) {
            Language::German => [
                'Kurzer deutscher Titel des Objekts, wenige Worte.',
                'Deutsche, sachliche Beschreibung für die spätere Suche.',
                'Deutsche Suchbegriffe, die ein Mensch eintippen würde.',
            ],
            Language::English => [
                'Short English title of the object, a few words.',
                'Factual English description for finding it later.',
                'English search terms a person would type.',
            ],
        };

        return [
            'title' => $schema->string()->required()->description($title),
            'description' => $schema->string()->required()->description($description),
            'search_terms' => $schema->array()->items($schema->string())->required()->description($searchTerms),
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
     */
    public function extract(array $attachments, ?int $timeout = null): ExtractedTexts
    {
        $prompt = match ($this->language) {
            Language::German => 'Analysiere die Fotos und liefere Titel, Beschreibung und Suchbegriffe.',
            Language::English => 'Analyze the photos and provide a title, a description and search terms.',
        };

        $result = $this->prompt($prompt, $attachments, timeout: $timeout)->toArray();

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
