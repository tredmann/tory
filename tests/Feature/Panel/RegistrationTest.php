<?php

use App\Models\User;
use Filament\Auth\Pages\Register;
use Livewire\Livewire;

test('new users can register through the panel', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'passwordConfirmation' => 'password123',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $this->assertAuthenticated();
    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

test('registration requires a matching password confirmation', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'passwordConfirmation' => 'different',
        ])
        ->call('register')
        ->assertHasFormErrors(['password']);

    $this->assertGuest();
});
