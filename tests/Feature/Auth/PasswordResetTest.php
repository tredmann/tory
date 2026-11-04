<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('the forgot password screen can be rendered', function () {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee('action="'.route('password.email').'"', false);
});

test('a reset link can be requested', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('the reset password screen can be rendered from the emailed link', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))
            ->assertOk()
            ->assertSee('name="token"', false)
            ->assertSee('value="'.$notification->token.'"', false);

        return true;
    });
});

test('the password can be reset with a valid token', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->post('/login', ['email' => $user->email, 'password' => 'new-password'])
            ->assertRedirect(route('dashboard'));

        return true;
    });
});
