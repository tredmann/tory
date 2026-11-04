<?php

use App\Models\User;

test('the login screen can be rendered', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('action="'.route('login').'"', false);
});

test('users can authenticate with valid credentials', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('users cannot authenticate with an invalid password', function () {
    $user = User::factory()->create();

    $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertRedirect('/login')->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});
