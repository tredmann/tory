<?php

use App\Enums\UserRole;
use App\Filament\Auth\AcceptInvite;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function inviteToken(User $user): string
{
    return Str::afterLast($user->issueInviteLink(), '/invite/');
}

test('the invite page asks for a password', function () {
    $this->get('/invite/'.inviteToken(User::invite('Anna', 'anna@example.com', UserRole::Member)))
        ->assertOk()
        ->assertSee('Passwort');
});

test('accepting the invite logs the user in with the new password', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);

    Livewire::test(AcceptInvite::class, ['token' => inviteToken($user)])
        ->fillForm(['password' => 'ein-langes-passwort', 'passwordConfirmation' => 'ein-langes-passwort'])
        ->call('accept')
        ->assertRedirect(filament()->getUrl());

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('the password must be confirmed', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);

    Livewire::test(AcceptInvite::class, ['token' => inviteToken($user)])
        ->fillForm(['password' => 'ein-langes-passwort', 'passwordConfirmation' => 'anders'])
        ->call('accept')
        ->assertHasFormErrors(['password']);
});

test('an unknown, used or locked link shows the invalid message', function (string $token) {
    $this->get('/invite/'.$token)
        ->assertSee('Dieser Link ist abgelaufen oder ungültig. Bitte frag nach einem neuen.')
        ->assertDontSee('Passwort speichern');
})->with([
    'unknown' => fn () => Str::random(32),
    'used' => function () {
        $user = User::factory()->create();
        $token = inviteToken($user);
        $user->acceptInvite('ein-langes-passwort');

        return $token;
    },
    'locked' => function () {
        $user = User::factory()->create();
        $token = inviteToken($user);
        $user->lock();

        return $token;
    },
]);

test('a link that expires after the page loaded is refused on submit', function () {
    $user = User::factory()->create();
    $before = $user->password;
    $page = Livewire::test(AcceptInvite::class, ['token' => inviteToken($user)]);

    $this->travel(25)->hours();

    $page->fillForm(['password' => 'ein-langes-passwort', 'passwordConfirmation' => 'ein-langes-passwort'])
        ->call('accept')
        ->assertSee('Dieser Link ist abgelaufen oder ungültig.');

    expect($user->fresh()->password)->toBe($before);
    $this->assertGuest();
});

test('the invite page is throttled', function () {
    foreach (range(1, 10) as $attempt) {
        $this->get('/invite/'.Str::random(32));
    }

    $this->get('/invite/'.Str::random(32))->assertTooManyRequests();
});
