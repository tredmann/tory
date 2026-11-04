<?php

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('a password reset link can be requested', function () {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $user->email])
        ->call('request')
        ->assertHasNoFormErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});
