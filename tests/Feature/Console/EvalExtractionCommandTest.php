<?php

use App\Ai\Agents\InventoryExtractor;
use App\Ai\Eval\EvalCase;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    Storage::fake('local');
});

test('the provider option is required', function () {
    InventoryExtractor::fake();

    $this->artisan('extraction:eval')->assertFailed();

    InventoryExtractor::assertNeverPrompted();
});

test('an unknown provider lists the available names', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    AiProvider::factory()->create(['name' => 'OpenAI']);
    InventoryExtractor::fake();

    $this->artisan('extraction:eval', ['--provider' => 'Nope'])
        ->expectsOutputToContain('Ollama lokal, OpenAI')
        ->assertFailed();

    InventoryExtractor::assertNeverPrompted();
});

test('runs must be at least 1', function (string $runs) {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    InventoryExtractor::fake();

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal', '--runs' => $runs])->assertFailed();

    InventoryExtractor::assertNeverPrompted();
})->with(['0', 'abc']);

test('the committed cases load', function () {
    $cases = EvalCase::loadAll(base_path('tests/Evals/extraction'));

    expect($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        expect($case->photos)->not->toBeEmpty();
        foreach ($case->photos as $photo) {
            expect(is_file($photo))->toBeTrue();
        }
    }
});

test('a report is saved and printed', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    InventoryExtractor::fake(fn () => ['title' => 'Testgerät', 'description' => 'Ein Gerät.', 'search_terms' => ['Ding']]);

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal'])
        ->expectsOutputToContain('RØDE Wireless GO')
        ->expectsOutputToContain('Testgerät')
        ->assertSuccessful();

    $files = Storage::disk('local')->files('evals');
    expect($files)->toHaveCount(1)
        ->and($files[0])->toContain('ollama-lokal')
        ->and(Storage::disk('local')->get($files[0]))->toContain('Pelikan 4001')->toContain('Ein Gerät.');
});

test('exits non-zero when the embedding provider is down', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    InventoryExtractor::fake();
    Embeddings::fake(fn () => throw new RuntimeException('down'));

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal'])->assertFailed();

    InventoryExtractor::assertNeverPrompted();
});

test('exits non-zero when every case failed', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    InventoryExtractor::fake(fn () => throw new RuntimeException('kaputt'));

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal'])->assertFailed();
});

test('exits zero when one case failed', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    $calls = 0;
    InventoryExtractor::fake(function () use (&$calls) {
        return ++$calls === 1 ? throw new RuntimeException('kaputt') : ['title' => 'Gerät', 'description' => 'x'];
    });

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal'])->assertSuccessful();
});

test('exits zero with a low score', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    InventoryExtractor::fake(fn () => ['title' => 'Gerät', 'description' => 'x']);

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal'])
        ->expectsOutputToContain('Suchwörter gefunden: 0 von')
        ->assertSuccessful();
});

test('totals show the range across runs', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    $calls = 0;
    InventoryExtractor::fake(function () use (&$calls) {
        $first = $calls++ < 5;

        return ['title' => 'Gerät', 'description' => 'x', 'search_terms' => $first ? ['mikrofon', 'rode', 'apc'] : []];
    });

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal', '--runs' => 2])
        ->expectsOutputToContain('Suchwörter gefunden: 0–')
        ->assertSuccessful();
});

test('an embedding failure after the probe still saves a report and exits zero', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    InventoryExtractor::fake(fn () => ['title' => 'Gerät', 'description' => 'x']);
    Embeddings::fake(fn ($prompt): array => in_array($prompt->inputs[0], ['apc', 'mikrofon'], true)
        ? throw new RuntimeException('Embedding weg')
        : [axis(0)]);

    $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal'])->assertSuccessful();

    $files = Storage::disk('local')->files('evals');
    expect($files)->toHaveCount(1)
        ->and(Storage::disk('local')->get($files[0]))->toContain('Embedding weg')->toContain('Gerät');
});

test('a broken case file is reported as an error', function () {
    AiProvider::factory()->create(['name' => 'Ollama lokal']);
    InventoryExtractor::fake();
    $original = file_get_contents(base_path('tests/Evals/extraction/cases.json'));
    file_put_contents(base_path('tests/Evals/extraction/cases.json'), json_encode([['name' => 'Kaputt', 'photos' => []]]));

    try {
        $this->artisan('extraction:eval', ['--provider' => 'Ollama lokal'])
            ->expectsOutputToContain('Kaputt')
            ->assertFailed();
    } finally {
        file_put_contents(base_path('tests/Evals/extraction/cases.json'), $original);
    }

    InventoryExtractor::assertNeverPrompted();
});
