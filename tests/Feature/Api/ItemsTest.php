<?php

use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());
});

it('lists items newest first, 24 per page', function () {
    Item::factory()->create(['title' => 'Alt', 'created_at' => now()->subDay()]);
    Item::factory()->count(24)->create();

    $this->getJson('/api/v1/items')
        ->assertOk()
        ->assertJsonCount(24, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonMissingPath('data.0.embedding');

    $this->getJson('/api/v1/items?page=2')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Alt');
});

it('searches by keyword', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn () => axis(9), $prompt->inputs));
    $match = Item::factory()->create(['title' => 'Akku Bohrer']);
    Item::factory()->create(['title' => 'Hammer']);

    $this->getJson('/api/v1/items?search=akku')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('falls back to keywords when embedding fails', function () {
    Embeddings::fake(fn () => throw new RuntimeException('down'));
    $match = Item::factory()->create(['title' => 'Akku Bohrer']);
    Item::factory()->create(['title' => 'Hammer']);

    $this->getJson('/api/v1/items?search=akku')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('treats a blank search as no search', function () {
    Item::factory()->count(2)->create();

    $this->getJson('/api/v1/items?search=%20%20')->assertOk()->assertJsonCount(2, 'data');
});

it('filters by location and by spot code in any case, combined with search', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn () => axis(9), $prompt->inputs));
    $location = Location::factory()->create();
    $spot = Spot::factory()->create(['location_id' => $location->id]);
    $otherSpot = Spot::factory()->create();
    $hit = Item::factory()->create(['spot_id' => $spot->id, 'title' => 'Akku']);
    Item::factory()->create(['spot_id' => $spot->id, 'title' => 'Hammer']);
    Item::factory()->create(['spot_id' => $otherSpot->id, 'title' => 'Akku']);

    $this->getJson("/api/v1/items?location={$location->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/items?spot='.strtolower($spot->code))->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/items?location={$location->id}&spot={$spot->code}&search=akku")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $hit->id);
});

it('gives an empty list for an unknown location or spot', function (string $query) {
    Item::factory()->create();

    $this->getJson("/api/v1/items?{$query}")->assertOk()->assertJsonCount(0, 'data');
})->with(['unknown uuid' => 'location=0198a2b0-0000-7000-8000-000000000000', 'not a uuid' => 'location=abc', 'unknown spot' => 'spot=ZZZZ', 'bad spot' => 'spot=!!']);

it('shows an item without its embedding', function () {
    $item = Item::factory()->create(['photos' => ['imports/abc.jpg']]);

    $response = $this->getJson("/api/v1/items/{$item->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.embedding')
        ->assertJsonPath('data.spot.code', $item->spot->code);

    expect($response->json('data.photos.0'))->toEndWith('/photos/abc.jpg');
});

it('updates only the sent fields', function () {
    $item = Item::factory()->create(['title' => 'Alt', 'description' => 'Text', 'search_terms' => ['a']]);
    $spot = Spot::factory()->create();

    $this->patchJson("/api/v1/items/{$item->id}", ['spot' => strtolower($spot->code)])
        ->assertOk()
        ->assertJsonPath('data.spot.code', $spot->code);

    $item->refresh();
    expect($item->title)->toBe('Alt')
        ->and($item->description)->toBe('Text')
        ->and($item->search_terms)->toBe(['a'])
        ->and($item->spot_id)->toBe($spot->id);
});

it('updates texts', function () {
    $item = Item::factory()->create();

    $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'Neu', 'description' => null, 'search_terms' => ['x', 'y']])
        ->assertOk()
        ->assertJsonPath('data.title', 'Neu')
        ->assertJsonPath('data.search_terms', ['x', 'y']);

    expect($item->fresh()->description)->toBeNull();
});

it('rejects an empty title', function () {
    $item = Item::factory()->create();

    $this->patchJson("/api/v1/items/{$item->id}", ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('rejects an unknown spot', function () {
    $item = Item::factory()->create();

    $this->patchJson("/api/v1/items/{$item->id}", ['spot' => 'ZZZZ'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('spot');
});

it('answers a conflict when updating during regeneration', function () {
    $item = Item::factory()->create(['title' => 'Alt']);
    $item->forceFill(['regenerating_at' => now()])->saveQuietly();

    $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'Neu'])
        ->assertStatus(409);

    expect($item->fresh()->title)->toBe('Alt');
});

it('deletes an item with its photos', function () {
    Storage::disk('local')->put('imports/abc.jpg', 'x');
    $item = Item::factory()->create(['photos' => ['imports/abc.jpg']]);

    $this->deleteJson("/api/v1/items/{$item->id}")->assertNoContent();

    expect(Item::find($item->id))->toBeNull();
    Storage::disk('local')->assertMissing('imports/abc.jpg');
});

it('answers 405 to PUT on an item', function () {
    $item = Item::factory()->create();

    $this->putJson("/api/v1/items/{$item->id}", ['title' => 'Neu'])->assertStatus(405);
});

it('treats an empty spot or location as no filter', function (string $query) {
    Item::factory()->count(2)->create();

    $this->getJson("/api/v1/items?{$query}")->assertOk()->assertJsonCount(2, 'data');
})->with(['spot=', 'location=', 'search=']);

it('does not fail on array filters', function (string $query, int $count) {
    Item::factory()->count(2)->create();

    $this->getJson("/api/v1/items?{$query}")->assertOk()->assertJsonCount($count, 'data');
})->with([
    'spot' => ['spot[]=x', 0],
    'location' => ['location[]=x', 0],
    'search' => ['search[]=x', 2],
]);

it('orders items created at the same moment by id descending', function () {
    $at = now();
    $first = Item::factory()->create(['created_at' => $at]);
    $second = Item::factory()->create(['created_at' => $at]);
    $expected = collect([$first, $second])->sortByDesc('id')->pluck('id')->all();

    expect(collect($this->getJson('/api/v1/items')->json('data'))->pluck('id')->all())->toBe($expected);
});
