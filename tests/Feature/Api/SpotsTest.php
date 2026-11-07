<?php

use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists all spots ordered by location, name and code', function () {
    $b = Location::factory()->create(['name' => 'B-Keller']);
    $a = Location::factory()->create(['name' => 'A-Garage']);
    Spot::factory()->for($b)->create(['name' => 'Regal']);
    $first = Spot::factory()->for($a)->create(['name' => 'Zange']);
    Spot::factory()->for($a)->create(['name' => 'Zange2']);

    $this->getJson('/api/v1/spots')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.code', $first->code)
        ->assertJsonPath('data.0.items_count', 0)
        ->assertJsonMissingPath('data.0.id');
});

it('shows a spot by code in any case', function () {
    $spot = Spot::factory()->create();

    $this->getJson('/api/v1/spots/'.strtolower($spot->code))
        ->assertOk()
        ->assertJsonPath('data.code', $spot->code)
        ->assertJsonPath('data.location.name', $spot->location->name)
        ->assertJsonPath('data.items_count', 0);
});

it('answers 404 for a code that cannot exist without querying', function () {
    DB::enableQueryLog();

    $this->getJson('/api/v1/spots/OOOO')->assertNotFound();

    $spotQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"spots"'));
    expect($spotQueries)->toBeEmpty();
});

it('creates a spot', function () {
    $location = Location::factory()->create();

    $this->postJson('/api/v1/spots', ['name' => 'Regal', 'note' => null, 'location' => $location->id])
        ->assertCreated()
        ->assertJsonPath('data.location.id', $location->id)
        ->assertJsonPath('data.name', 'Regal');

    expect(Spot::sole()->code)->toMatch('/^[2-9A-HJ-NP-Z]{4}$/');
});

it('returns the code of a created spot', function () {
    $location = Location::factory()->create();

    $response = $this->postJson('/api/v1/spots', ['name' => 'Regal', 'location' => $location->id]);

    expect($response->json('data.code'))->toMatch('/^[2-9A-HJ-NP-Z]{4}$/');
});

it('rejects a spot without name or with an unknown location', function () {
    $this->postJson('/api/v1/spots', ['location' => fake()->uuid()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'location']);
});
