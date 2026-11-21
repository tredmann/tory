<?php

use App\Models\User;

test('it makes a user an administrator', function (): void {
    $user = User::factory()->create(['name' => 'Tobias', 'email' => 'owner@example.com']);

    $this->artisan('users:make-admin', ['email' => 'owner@example.com'])
        ->expectsOutputToContain('Tobias ist jetzt Administrator.')
        ->assertSuccessful();

    expect($user->fresh()->isAdmin())->toBeTrue();
});

test('it fails for an unknown email', function (): void {
    $this->artisan('users:make-admin', ['email' => 'niemand@example.com'])
        ->expectsOutputToContain('Kein Nutzer mit der E-Mail niemand@example.com.')
        ->assertFailed();
});
