<?php

use App\Mcp\ClaudeConnections;
use App\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

function refreshMcpToken(string $clientId, string $refreshToken)
{
    return postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $refreshToken,
    ]);
}

function pingMcp(string $accessToken)
{
    auth()->forgetGuards();

    return withToken($accessToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ],
    ]);
}

it('lists one connection per client however often it refreshed', function () {
    $user = User::factory()->create();
    $tokens = issueMcpTokens($user, 'Claude Desktop');

    $first = refreshMcpToken($tokens['client_id'], $tokens['refresh_token'])->assertOk();
    refreshMcpToken($tokens['client_id'], $first->json('refresh_token'))->assertOk();

    $connections = (new ClaudeConnections)->for($user);

    expect($connections)->toHaveCount(1)
        ->and($connections->first()->clientId)->toBe($tokens['client_id'])
        ->and($connections->first()->name)->toBe('Claude Desktop')
        ->and($connections->first()->host)->toBe('claude.ai');
});

it('stops tool calls and refreshes after disconnecting', function () {
    $user = User::factory()->create();
    $tokens = issueMcpTokens($user);

    pingMcp($tokens['access_token'])->assertOk();

    (new ClaudeConnections)->disconnect($user, $tokens['client_id']);

    pingMcp($tokens['access_token'])->assertUnauthorized();
    refreshMcpToken($tokens['client_id'], $tokens['refresh_token'])->assertStatus(400);
    expect((new ClaudeConnections)->for($user))->toBeEmpty();
});

it('shows and disconnects only the own connections', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $tokensA = issueMcpTokens($a);
    $tokensB = issueMcpTokens($b, existingClientId: $tokensA['client_id']);

    $connections = new ClaudeConnections;
    expect($connections->for($a))->toHaveCount(1)
        ->and($connections->for($b))->toHaveCount(1);

    $connections->disconnect($a, $tokensA['client_id']);

    pingMcp($tokensA['access_token'])->assertUnauthorized();
    pingMcp($tokensB['access_token'])->assertOk();
    refreshMcpToken($tokensB['client_id'], $tokensB['refresh_token'])->assertOk();
});

it('dates a connection from the first token, not from a refresh', function () {
    $user = User::factory()->create();
    $tokens = issueMcpTokens($user);
    $connectedAt = (new ClaudeConnections)->for($user)->first()->connectedAt;

    $this->travel(1)->days();
    refreshMcpToken($tokens['client_id'], $tokens['refresh_token'])->assertOk();

    $connections = (new ClaudeConnections)->for($user);

    expect($connections)->toHaveCount(1)
        ->and($connections->first()->connectedAt->equalTo($connectedAt))->toBeTrue();
});
