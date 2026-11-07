<?php

use App\Mcp\Servers\ToryServer;
use App\Mcp\Tools\CreateSpotTool;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

it('creates a spot in the named location', function () {
    $location = Location::factory()->create(['name' => 'Keller']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(CreateSpotTool::class, ['name' => 'Regal 5', 'location' => 'keller', 'note' => 'ganz hinten'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('name', 'Regal 5')
            ->where('note', 'ganz hinten')
            ->where('location', 'Keller')
            ->whereType('code', 'string')
            ->etc());

    $spot = Spot::query()->firstWhere('name', 'Regal 5');

    expect($spot->location_id)->toBe($location->id)
        ->and($spot->code)->toHaveLength(4);
});

it('refuses an unknown location and lists the existing ones', function () {
    Location::factory()->create(['name' => 'Keller']);
    Location::factory()->create(['name' => 'Dachboden']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(CreateSpotTool::class, ['name' => 'Regal 5', 'location' => 'Garage'])
        ->assertHasErrors(['Diesen Lagerort gibt es nicht', 'Keller', 'Dachboden']);

    expect(Spot::query()->where('name', 'Regal 5')->exists())->toBeFalse();
});

it('validates the name like the panel', function (string $name) {
    Location::factory()->create(['name' => 'Keller']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(CreateSpotTool::class, ['name' => $name, 'location' => 'Keller'])
        ->assertHasErrors();

    expect(Spot::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'too long' => fn () => str_repeat('a', 256),
]);
