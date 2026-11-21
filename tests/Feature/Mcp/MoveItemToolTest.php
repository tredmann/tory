<?php

use App\Enums\Language;
use App\Mcp\Servers\ToryServer;
use App\Mcp\Tools\MoveItemTool;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

it('moves the item to the spot', function (): void {
    $item = Item::factory()->create();
    $spot = Spot::factory()->for(Location::factory(['name' => 'Keller']))->create(['name' => 'Regal 3']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(MoveItemTool::class, ['item' => $item->id, 'spot' => $spot->code])
        ->assertOk()
        ->assertSee(['Regal 3', 'Keller'])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('id', $item->id)
            ->where('title', $item->title)
            ->where('spot.code', $spot->code)
            ->where('spot.location', 'Keller')
            ->etc());

    expect($item->fresh()->spot_id)->toBe($spot->id);
});

it('accepts a lower-case code', function (): void {
    $item = Item::factory()->create();
    $spot = Spot::factory()->create();

    ToryServer::actingAs(User::factory()->create())
        ->tool(MoveItemTool::class, ['item' => $item->id, 'spot' => strtolower($spot->code)])
        ->assertOk();

    expect($item->fresh()->spot_id)->toBe($spot->id);
});

it('refuses a value that cannot be a code', function (): void {
    $item = Item::factory()->create();
    $before = $item->spot_id;

    ToryServer::actingAs(User::factory()->create())
        ->tool(MoveItemTool::class, ['item' => $item->id, 'spot' => 'K0OI'])
        ->assertHasErrors(['Diesen Lagerplatz gibt es nicht.']);

    expect($item->fresh()->spot_id)->toBe($before);
});

it('refuses a code that no spot has', function (): void {
    $item = Item::factory()->create();

    ToryServer::actingAs(User::factory()->create())
        ->tool(MoveItemTool::class, ['item' => $item->id, 'spot' => 'ZZZZ'])
        ->assertHasErrors(['Diesen Lagerplatz gibt es nicht.']);
});

it('refuses an id that is no uuid', function (): void {
    $spot = Spot::factory()->create();

    ToryServer::actingAs(User::factory()->create())
        ->tool(MoveItemTool::class, ['item' => 'bohrmaschine', 'spot' => $spot->code])
        ->assertHasErrors(['Diesen Gegenstand gibt es nicht.']);
});

it('refuses while the item is regenerating', function (): void {
    $item = Item::factory()->create(['regenerating_at' => now()]);
    $before = $item->spot_id;
    $spot = Spot::factory()->create();

    ToryServer::actingAs(User::factory()->create())
        ->tool(MoveItemTool::class, ['item' => $item->id, 'spot' => $spot->code])
        ->assertHasErrors(['Der Gegenstand wird gerade neu generiert. Bitte versuch es gleich noch einmal.']);

    expect($item->fresh()->spot_id)->toBe($before);
});

it('leaves the last used spot alone', function (): void {
    $lastSpot = Spot::factory()->create();
    $user = User::factory()->create(['last_spot_id' => $lastSpot->id]);
    $item = Item::factory()->create();
    $spot = Spot::factory()->create();

    ToryServer::actingAs($user)
        ->tool(MoveItemTool::class, ['item' => $item->id, 'spot' => $spot->code])
        ->assertOk();

    expect($user->fresh()->last_spot_id)->toBe($lastSpot->id);
});

it('answers in English to an English token owner', function (): void {
    $spot = Spot::factory()->for(Location::factory(['name' => 'Keller']))->create(['name' => 'Regal 3']);
    $item = Item::factory()->create(['title' => 'Bohrmaschine']);
    $user = User::factory()->create();
    $user->forceFill(['locale' => Language::English])->save();

    callMcpTool($user, 'move-item-tool', ['item' => $item->id, 'spot' => $spot->code])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', "Bohrmaschine is now on Regal 3 ({$spot->code}), Keller.");
});

it('answers in German to a German token owner', function (): void {
    $spot = Spot::factory()->for(Location::factory(['name' => 'Keller']))->create(['name' => 'Regal 3']);
    $item = Item::factory()->create(['title' => 'Bohrmaschine']);

    callMcpTool(User::factory()->create(), 'move-item-tool', ['item' => $item->id, 'spot' => $spot->code])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', "Bohrmaschine liegt jetzt auf Regal 3 ({$spot->code}), Keller.");
});
