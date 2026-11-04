<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

test('the verification notice screen can be rendered for unverified users', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/email/verify')
        ->assertOk()
        ->assertSee('action="'.route('verification.send').'"', false);
});

test('unverified users are redirected to the notice from verified routes', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/email/verify');
});

test('email can be verified from the signed link', function () {
    Event::fake();
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $this->actingAs($user)->get($url)->assertRedirect(route('dashboard').'?verified=1');

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('email is not verified with an invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong@example.com')]
    );

    $this->actingAs($user)->get($url);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
