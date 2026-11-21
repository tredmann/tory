<?php

use App\Enums\Language;
use App\Mcp\Servers\ToryServer;
use App\Mcp\Tools\FindItemsTool;
use App\Models\Item;
use App\Models\Location;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

it('finds items with their spot and location', function (): void {
    $spot = Spot::factory()->for(Location::factory(['name' => 'Keller']))->create(['name' => 'Regal 3']);
    Item::factory()->for($spot)->create(['title' => 'Bohrmaschine']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Bohrmaschine'])
        ->assertOk()
        ->assertSee(['Bohrmaschine', 'Regal 3', 'Keller', $spot->code]);
});

it('returns at most ten items', function (): void {
    Item::factory()->count(12)->create(['title' => 'Schraube']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Schraube'])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json->has('items', 10)->etc());
});

it('says when nothing matched', function (): void {
    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Gibtsnicht'])
        ->assertOk()
        ->assertSee('Nichts gefunden');
});

it('never shows the internal spot id or the embedding', function (): void {
    $spot = Spot::factory()->create();
    Item::factory()->for($spot)->create(['title' => 'Hammer']);

    ToryServer::actingAs(User::factory()->create())
        ->tool(FindItemsTool::class, ['query' => 'Hammer'])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('items.0', fn (AssertableJson $item): AssertableJson => $item
                ->missing('spot_id')
                ->missing('embedding')
                ->has('spot', fn (AssertableJson $s): AssertableJson => $s->missing('id')->missing('location_id')->etc())
                ->etc())
            ->etc());
});

it('shows the note on one line and shortened', function (): void {
    Item::factory()->create(['title' => 'Beamer', 'notes' => "Fernbedienung fehlt\nKabel im Keller"]);
    Item::factory()->create(['title' => 'Router', 'notes' => str_repeat('a', 300)]);
    Item::factory()->create(['title' => 'Monitor']);
    $user = User::factory()->create();

    ToryServer::actingAs($user)
        ->tool(FindItemsTool::class, ['query' => 'Beamer'])
        ->assertSee('Beamer')
        ->assertSee('– Bemerkung: Fernbedienung fehlt Kabel im Keller')
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.notes', "Fernbedienung fehlt\nKabel im Keller")->etc());

    ToryServer::actingAs($user)
        ->tool(FindItemsTool::class, ['query' => 'Router'])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.notes', fn (string $notes): bool => mb_strlen($notes) <= 203)->etc());

    ToryServer::actingAs($user)
        ->tool(FindItemsTool::class, ['query' => 'Monitor'])
        ->assertDontSee('Bemerkung')
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json->where('items.0.notes', null)->etc());
});

it('answers in English to an English token owner', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['locale' => Language::English])->save();

    callMcpTool($user, 'find-items-tool', ['query' => 'Gibtsnicht'])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'Nothing found.');
});

it('answers in German to a German token owner', function (): void {
    callMcpTool(User::factory()->create(), 'find-items-tool', ['query' => 'Gibtsnicht'])
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'Nichts gefunden.');
});
