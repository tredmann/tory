<?php

use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Sanctum::actingAs(User::factory()->create());
});

it('lists locations by name with spots_count', function (): void {
    Location::factory()->create(['name' => 'Zimmer']);
    $garage = Location::factory()->create(['name' => 'Garage']);
    Spot::factory()->for($garage)->count(2)->create();

    $this->getJson('/api/v1/locations')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Garage')
        ->assertJsonPath('data.0.spots_count', 2)
        ->assertJsonPath('data.1.spots_count', 0);
});

it('creates a location', function (): void {
    $this->postJson('/api/v1/locations', ['name' => 'Keller'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Keller')
        ->assertJsonStructure(['data' => ['id']]);
});

it('rejects a duplicate location name', function (): void {
    Location::factory()->create(['name' => 'Keller']);

    $this->postJson('/api/v1/locations', ['name' => 'Keller'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'Dieser Wert für Name ist bereits vergeben.']);
});
