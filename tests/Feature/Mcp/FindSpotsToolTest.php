<?php

use App\Mcp\Servers\ToryServer;
use App\Mcp\Tools\FindSpotsTool;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

it('matches part of a spot name in any case', function () {
    $spot = Spot::factory()->create(['name' => 'Regal 3']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindSpotsTool::class, ['name' => 'regal'])
        ->assertOk()
        ->assertSee($spot->code);
});

it('matches an exact code in any case', function () {
    $spot = Spot::factory()->create(['name' => 'Kiste']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindSpotsTool::class, ['name' => strtolower($spot->code)])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('spots', 1)
            ->where('spots.0.code', $spot->code)
            ->etc());
});

it('lists every match so claude can ask back', function () {
    Spot::factory()->for(Location::factory(['name' => 'Keller']))->create(['name' => 'Kiste']);
    Spot::factory()->for(Location::factory(['name' => 'Dachboden']))->create(['name' => 'Kiste']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindSpotsTool::class, ['name' => 'Kiste'])
        ->assertSee(['Keller', 'Dachboden'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('spots', 2)->missing('locations')->etc());
});

it('narrows by location name', function () {
    $keller = Spot::factory()->for(Location::factory(['name' => 'Keller']))->create(['name' => 'Kiste']);
    Spot::factory()->for(Location::factory(['name' => 'Dachboden']))->create(['name' => 'Kiste']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindSpotsTool::class, ['name' => 'Kiste', 'location' => 'keller'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('spots', 1)
            ->where('spots.0.code', $keller->code)
            ->etc());
});

it('offers the locations when nothing matched', function () {
    Location::factory()->create(['name' => 'Keller']);
    Location::factory()->create(['name' => 'Dachboden']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindSpotsTool::class, ['name' => 'Nirgends'])
        ->assertOk()
        ->assertSee(['Keller', 'Dachboden'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('spots', 0)
            ->has('locations', 2)
            ->etc());
});
