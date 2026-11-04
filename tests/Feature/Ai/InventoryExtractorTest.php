<?php

use App\Ai\Agents\InventoryExtractor;
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

test('instructions are German and forbid inventing details', function () {
    $instructions = (string) (new InventoryExtractor)->instructions();

    expect($instructions)->toContain('Deutsch')->toContain('sichtbar');
});
