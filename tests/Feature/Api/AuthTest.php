<?php

use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('login returns a token named after the device', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'secret-pass',
        'device_name' => 'iPhone',
    ]);

    $response->assertCreated();
    expect($response->json('token'))->toBeString();
    expect($user->tokens()->sole()->name)->toBe('iPhone');
});

it('login rejects wrong credentials', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Diese Zugangsdaten stimmen nicht mit unseren Daten überein.']);

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('login refuses an unverified user', function () {
    $user = User::factory()->unverified()->create(['password' => 'secret-pass']);

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'secret-pass',
        'device_name' => 'iPhone',
    ])->assertForbidden()
        ->assertJson(['message' => 'Bitte bestätige zuerst deine E-Mail-Adresse.']);

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('login is rate limited', function () {
    $user = User::factory()->create();
    $payload = ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'iPhone'];

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/login', $payload)->assertUnprocessable();
    }

    $this->postJson('/api/v1/login', $payload)->assertTooManyRequests();
});

it('login requires a device name', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'secret-pass',
    ])->assertUnprocessable()->assertJsonValidationErrors(['device_name']);
});

it('logout revokes only the current token', function () {
    $user = User::factory()->create();
    $first = $user->createToken('one')->plainTextToken;
    $user->createToken('two');

    $this->withToken($first)->postJson('/api/v1/logout')->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['two']);
});

it('user returns the current user with the last spot code', function () {
    $spot = Spot::factory()->create();
    $user = User::factory()->create(['last_spot_id' => $spot->id]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('data.last_spot', $spot->code)
        ->assertJsonMissingPath('data.last_spot_id');
});

it('requests without a token are unauthorized', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

it('an unverified user with a token is forbidden', function () {
    Sanctum::actingAs(User::factory()->unverified()->create());

    $this->getJson('/api/v1/user')->assertForbidden();
});
