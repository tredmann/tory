<?php

use App\Models\User;

test('the registration screen can be rendered', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSee('action="'.route('register').'"', false);
});

test('new users can register and are sent to the dashboard', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard'));
    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

test('registration requires a confirmed password', function () {
    $this->from('/register')->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'different',
    ])->assertRedirect('/register')->assertSessionHasErrors('password');

    $this->assertGuest();
});
