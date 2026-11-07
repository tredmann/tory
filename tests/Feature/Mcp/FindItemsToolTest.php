<?php

use App\Mcp\Servers\ToryServer;
use App\Mcp\Tools\FindItemsTool;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

it('finds items with their spot and location', function () {
    $spot = Spot::factory()->for(Location::factory(['name' => 'Keller']))->create(['name' => 'Regal 3']);
    Item::factory()->for($spot)->create(['title' => 'Bohrmaschine']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Bohrmaschine'])
        ->assertOk()
        ->assertSee(['Bohrmaschine', 'Regal 3', 'Keller', $spot->code]);
});

it('returns at most ten items', function () {
    Item::factory()->count(12)->create(['title' => 'Schraube']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Schraube'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('items', 10)->etc());
});

it('says when nothing matched', function () {
    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Gibtsnicht'])
        ->assertOk()
        ->assertSee('Nichts gefunden');
});

it('never shows the internal spot id or the embedding', function () {
    $spot = Spot::factory()->create();
    Item::factory()->for($spot)->create(['title' => 'Hammer']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Hammer'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('items.0', fn (AssertableJson $item) => $item
                ->missing('spot_id')
                ->missing('embedding')
                ->has('spot', fn (AssertableJson $s) => $s->missing('id')->missing('location_id')->etc())
                ->etc())
            ->etc());
});
