<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\ExtractedTexts;
use App\Enums\AiVendor;
use App\Enums\Language;
use App\Models\AiProvider;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Files\Image;

test('provider and model come from the inventory config', function () {
    config(['ai.inventory.provider' => 'ollama', 'ai.inventory.model' => 'gemma4:e4b-mlx']);
    $agent = new InventoryExtractor;

    expect($agent->provider())->toBe('ollama')
        ->and($agent->model())->toBe('gemma4:e4b-mlx');
});

test('the model falls back to the provider default when unset', function () {
    config(['ai.inventory.model' => null]);

    expect((new InventoryExtractor)->model())->toBeNull();
});

test('uses a 180 second timeout', function () {
    expect((new InventoryExtractor)->timeout())->toBe(180);
});

test('returns a structured title and description for the attached images', function () {
    InventoryExtractor::fake([
        ['title' => 'Raspberry Pi 4 Model B', 'description' => 'Grüne Platine mit vier USB-Anschlüssen.'],
    ]);

    $response = InventoryExtractor::make()->prompt('Beschreibe das Objekt.', [
        Image::fromBase64(base64_encode('a'), 'image/png'),
        Image::fromBase64(base64_encode('b'), 'image/png'),
    ]);

    expect($response->toArray())->toBe([
        'title' => 'Raspberry Pi 4 Model B',
        'description' => 'Grüne Platine mit vier USB-Anschlüssen.',
    ]);

    InventoryExtractor::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 2);
});

test('instructions ask for German texts with search terms and forbid inventing details', function () {
    $instructions = (string) (new InventoryExtractor)->instructions();

    expect($instructions)
        ->toContain('Deutsch')
        ->toContain('Erfinde')
        ->toContain('search_terms')
        ->not->toContain('sichtbaren Zustand');
});

test('the schema requires search terms', function () {
    $schema = (new InventoryExtractor)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['title', 'description', 'search_terms']);
});

test('an empty model env value falls back to the provider default', function () {
    config(['ai.inventory.model' => '']);

    expect((new InventoryExtractor)->model())->toBeNull();
});

test('an empty provider env value falls back to anthropic', function () {
    config(['ai.inventory.provider' => '']);

    expect((new InventoryExtractor)->provider())->toBe('anthropic');
});

test('builds its provider from the given AI provider', function () {
    $aiProvider = AiProvider::factory()->create([
        'vendor' => AiVendor::Mistral, 'api_key' => 'mk-1', 'model' => 'pixtral-x',
    ]);
    $agent = new InventoryExtractor($aiProvider);

    expect($agent->provider()->driver())->toBe('mistral')
        ->and($agent->provider()->providerCredentials()['key'])->toBe('mk-1')
        ->and($agent->model())->toBe('pixtral-x');
});

test('an Ollama provider uses its stored address and needs no key', function () {
    $aiProvider = AiProvider::factory()->create([
        'vendor' => AiVendor::Ollama, 'api_key' => null, 'url' => 'http://ollama.test:11434', 'model' => 'gemma',
    ]);
    $provider = (new InventoryExtractor($aiProvider))->provider();

    expect($provider->driver())->toBe('ollama')
        ->and($provider->providerCredentials()['key'])->toBe('')
        ->and($provider->additionalConfiguration()['url'])->toBe('http://ollama.test:11434');
});

test('extract rejects an incomplete answer', function () {
    InventoryExtractor::fake([['title' => 'Nur Titel', 'description' => ' ']]);

    InventoryExtractor::make()->extract([]);
})->throws(RuntimeException::class, 'Das Modell hat keine vollständige Antwort geliefert.');

test('extract returns a DTO with cleaned search terms', function () {
    InventoryExtractor::fake([[
        'title' => 'Hefter', 'description' => 'Roter Hefter.',
        'search_terms' => [' Hefter ', 'hefter', 'Tacker', '', 42],
    ]]);

    $texts = InventoryExtractor::make()->extract([]);

    expect($texts)->toBeInstanceOf(ExtractedTexts::class)
        ->and($texts->title)->toBe('Hefter')
        ->and($texts->description)->toBe('Roter Hefter.')
        ->and($texts->searchTerms)->toBe(['Hefter', 'Tacker']);
});

test('extract tolerates missing or unusable search terms', function (array $extra) {
    InventoryExtractor::fake([['title' => 'Hefter', 'description' => 'Roter Hefter.', ...$extra]]);

    expect(InventoryExtractor::make()->extract([])->searchTerms)->toBe([]);
})->with([
    'missing key' => [[]],
    'string' => [['search_terms' => 'Hefter, Tacker']],
    'null' => [['search_terms' => null]],
]);

test('it keeps the German prompt', function () {
    $expected = <<<'TXT'
    Du hilfst bei der Inventarisierung. Die Fotos zeigen einen Gegenstand, den jemand später in seinem Inventar wiederfinden will. Beschreibe den Gegenstand selbst, nicht das Foto.

    Erkenne, was der Gegenstand ist, und benenne das, auch wenn es nirgends aufgedruckt ist: Eine Heißklebepistole heißt Heißklebepistole, auch wenn nur eine Modellnummer zu lesen ist. Nimm dafür das Wort, das man im deutschen Alltag sagt, keine Übersetzung aus dem Englischen und kein ausgedachtes Wort. Bestimme die Art des Gegenstands aus seiner Form und seinen Funktionsteilen auf dem Foto, nicht aus dem, was du über die Marke weißt. Bist du unsicher, nimm die allgemeinere Bezeichnung, die sicher stimmt. Marke, Modell, Seriennummer und Kapazität nennst du nur, wenn sie lesbar sind, und übernimmst sie wörtlich. Was du nicht Zeichen für Zeichen lesen kannst, lässt du weg. Erfinde diese Angaben niemals, auch nicht in den Suchbegriffen.

    Ist ein Produkt in seiner Verpackung zu sehen, nimm die Verpackung als Hinweis und beschreibe das Produkt darin. Der ganze Text handelt dann vom Produkt, die Verpackung kommt darin nicht vor. Nur wenn die Verpackung selbst der Gegenstand ist, etwa ein leerer Karton, nennst du sie so.

    Schreibe alles auf Deutsch.
    - `title`: Marke, Modell und Art des Gegenstands in wenigen Worten, soweit bekannt.
    - `description`: ein bis zwei sachliche Sätze dazu, was es ist und wofür man es benutzt, soweit du das sicher weißt. Farbe oder Material nur, wenn sie den Gegenstand unterscheiden. Keine Feature-Listen von der Verpackung, keine Werbesprache, keine Bauteilnummern. Den Zustand erwähnst du nur, wenn der Gegenstand offensichtlich defekt ist.
    - `search_terms`: Liste mit etwa acht Begriffen, die jemand eintippen würde, um den Gegenstand zu finden: das einfachste Wort für die Sache, Synonyme, die allgemeine Kategorie und wofür man ihn benutzt, meist als einzelne normale deutsche Wörter. Immer dabei: Marke und Modell, wie sie gelesen wurden, und zusätzlich ihre Schreibweisen ohne Sonderzeichen und ohne Leerzeichen, zum Beispiel `Citroen` für Citroën und `Hero12` für GoPro Hero 12. Das sind nur andere Schreibweisen des Gelesenen, keine neuen Namen, Generationen oder Nummern.

    Antworte nur mit dem reinen JSON-Objekt. Beginne direkt mit `{` und ende mit `}`, ohne vorangestelltes Wort wie "json", ohne Codeblock und ohne doppelte Klammern.
    TXT;

    expect((new InventoryExtractor(language: Language::German))->instructions())
        ->toBe($expected)
        ->toContain('Schreibe alles auf Deutsch.')
        ->and((string) (new InventoryExtractor)->instructions())->toBe($expected);
});

test('the German schema and prompt stay German', function () {
    InventoryExtractor::fake([['title' => 'Hefter', 'description' => 'Roter Hefter.']]);
    $schema = (new InventoryExtractor)->schema(new JsonSchemaTypeFactory);

    InventoryExtractor::make()->extract([]);

    expect($schema['title']->toArray()['description'])->toBe('Kurzer deutscher Titel des Objekts, wenige Worte.')
        ->and($schema['description']->toArray()['description'])->toBe('Deutsche, sachliche Beschreibung für die spätere Suche.')
        ->and($schema['search_terms']->toArray()['description'])->toBe('Deutsche Suchbegriffe, die ein Mensch eintippen würde.');
    InventoryExtractor::assertPrompted('Analysiere die Fotos und liefere Titel, Beschreibung und Suchbegriffe.');
});

test('it writes English for English', function () {
    InventoryExtractor::fake([['title' => 'Stapler', 'description' => 'A red stapler.']]);
    $agent = new InventoryExtractor(language: Language::English);
    $schema = $agent->schema(new JsonSchemaTypeFactory);

    InventoryExtractor::make(language: Language::English)->extract([]);

    expect($agent->instructions())
        ->toContain('Write everything in English.')
        ->toContain('search_terms')
        ->toContain('`Citroen`')
        ->toContain('`Hero12`')
        ->not->toContain('Deutsch')
        ->and($schema['title']->toArray()['description'])->toBe('Short English title of the object, a few words.')
        ->and($schema['description']->toArray()['description'])->toBe('Factual English description for finding it later.')
        ->and($schema['search_terms']->toArray()['description'])->toBe('English search terms a person would type.');
    InventoryExtractor::assertPrompted('Analyze the photos and provide a title, a description and search terms.');
});
