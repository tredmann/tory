<?php

use App\Models\User;
use Laravel\Passport\Client;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

function mcpMessage(string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
}

function initializeMessage(): array
{
    return mcpMessage('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'Claude', 'version' => '1.0'],
    ]);
}

function registerClient(string $name, string $redirect): string
{
    return postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => [$redirect]])
        ->assertCreated()
        ->json('client_id');
}

function authorizeQuery(string $clientId, string $redirect): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'zustand',
        'code_challenge' => strtr(rtrim(base64_encode(hash('sha256', str_repeat('v', 64), true)), '='), '+/', '-_'),
        'code_challenge_method' => 'S256',
    ]);
}

test('the protected resource and authorization server are discoverable', function () {
    getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonStructure(['resource', 'authorization_servers']);

    getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp'))
        ->assertJsonStructure(['authorization_servers']);

    getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'));
});

test('any client may register', function () {
    registerClient('Irgendwas', 'https://example.org/cb');

    expect(Client::query()->where('name', 'Irgendwas')->exists())->toBeTrue();
});

test('an unauthenticated mcp request is told how to log in', function () {
    $response = postJson('/mcp', initializeMessage())
        ->assertUnauthorized()
        ->assertHeaderMissing('Location');

    expect($response->headers->get('WWW-Authenticate'))->toContain('oauth-protected-resource');
});

test('an unauthenticated mcp request is not redirected even without a json accept header', function () {
    $response = $this->call('POST', '/mcp', content: json_encode(initializeMessage()), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'text/event-stream',
    ])
        ->assertUnauthorized()
        ->assertHeaderMissing('Location');

    expect($response->headers->get('WWW-Authenticate'))->toContain('oauth-protected-resource');
});

test('a guest is sent to the tory login before authorizing', function () {
    $clientId = registerClient('Claude', 'https://claude.ai/api/mcp/auth_callback');

    get(authorizeQuery($clientId, 'https://claude.ai/api/mcp/auth_callback'))
        ->assertRedirect(route('filament.admin.auth.login'))
        ->assertSessionHas('url.intended', fn (string $intended): bool => str_starts_with($intended, url('/oauth/authorize?')));
});

test('the consent page names the requesting program', function () {
    $clientId = registerClient('Claude', 'https://claude.ai/api/mcp/auth_callback');

    actingAs(User::factory()->create())
        ->get(authorizeQuery($clientId, 'https://claude.ai/api/mcp/auth_callback'))
        ->assertOk()
        ->assertSee('Zugriff erlauben?')
        ->assertSee('Claude')
        ->assertSee('claude.ai')
        ->assertSee('Erlauben')
        ->assertSee('Ablehnen');
});

test('the consent page escapes the client name', function () {
    $clientId = registerClient('<script>alert(1)</script>', 'https://evil.example/cb');

    actingAs(User::factory()->create())
        ->get(authorizeQuery($clientId, 'https://evil.example/cb'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
});

test('a connected client can list the tools', function () {
    $tokens = issueMcpTokens(User::factory()->create());

    expect($tokens['refresh_token'])->not->toBeEmpty();

    withToken($tokens['access_token'])
        ->postJson('/mcp', mcpMessage('tools/list'))
        ->assertOk()
        ->assertJsonStructure(['result' => ['tools']]);
});

test('an unverified user cannot use the server', function () {
    $tokens = issueMcpTokens(User::factory()->unverified()->create());

    withToken($tokens['access_token'])
        ->postJson('/mcp', mcpMessage('tools/list'))
        ->assertForbidden();
});

test('client registration is throttled', function () {
    foreach (range(1, 10) as $number) {
        postJson('/oauth/register', ['client_name' => "Client {$number}", 'redirect_uris' => ['https://example.org/cb']])->assertCreated();
    }

    postJson('/oauth/register', ['client_name' => 'Zu viel', 'redirect_uris' => ['https://example.org/cb']])->assertTooManyRequests();
});
