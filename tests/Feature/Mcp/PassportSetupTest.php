<?php

use App\Models\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

use function Pest\Laravel\withToken;

test('passport tokens can belong to a uuid user', function (): void {
    $user = User::factory()->create();
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback']);

    $token = new Token;
    $token->id = bin2hex(random_bytes(40));
    $token->user_id = $user->id;
    $token->client_id = $client->id;
    $token->revoked = false;
    $token->save();

    expect($user->oauthTokens()->sole()->client_id)->toBe($client->id);
});

test('sanctum tokens still work beside passport', function (): void {
    $user = User::factory()->create();
    $plain = $user->createToken('iPhone')->plainTextToken;

    withToken($plain)->getJson('/api/v1/user')->assertOk();
});

test('tokens last a hundred years', function (): void {
    expect(Passport::tokensExpireIn()->totalYears)->toBe(100.0)
        ->and(Passport::refreshTokensExpireIn()->totalYears)->toBe(100.0);
});
