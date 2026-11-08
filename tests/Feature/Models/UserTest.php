<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('a new user is a member', function () {
    expect(User::factory()->create()->role)->toBe(UserRole::Member);
});

test('an invited user is unverified and cannot log in with a guessable password', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);

    expect($user->status())->toBe(UserStatus::Invited)
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

test('the invite link is short, path-only and stores only a hash', function () {
    $user = User::factory()->create();

    $link = $user->issueInviteLink();
    $token = Str::afterLast($link, '/invite/');

    expect($link)->toMatch('#/invite/[A-Za-z0-9]{32}$#')
        ->and($user->fresh()->getAttributes()['invite_token'])->toBe(hash('sha256', $token))
        ->and(User::findByInviteToken($token)?->is($user))->toBeTrue();
});

test('an invite link expires after 24 hours', function () {
    $user = User::factory()->create();
    $token = Str::afterLast($user->issueInviteLink(), '/invite/');

    $this->travel(24)->hours();
    $this->travel(1)->second();

    expect(User::findByInviteToken($token))->toBeNull();
});

test('a new link replaces the old one', function () {
    $user = User::factory()->create();
    $old = Str::afterLast($user->issueInviteLink(), '/invite/');
    $user->issueInviteLink();

    expect(User::findByInviteToken($old))->toBeNull();
});

test('accepting an invite sets the password, verifies and uses up the link', function () {
    $user = User::invite('Anna', 'anna@example.com', UserRole::Member);
    $token = Str::afterLast($user->issueInviteLink(), '/invite/');

    $user->acceptInvite('ein-langes-passwort');

    expect(Hash::check('ein-langes-passwort', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->status())->toBe(UserStatus::Active)
        ->and(User::findByInviteToken($token))->toBeNull();
});

test('locking clears an open link and unlocking restores the status', function () {
    $user = User::factory()->create();
    $token = Str::afterLast($user->issueInviteLink(), '/invite/');

    $user->lock();
    expect($user->status())->toBe(UserStatus::Locked)
        ->and(User::findByInviteToken($token))->toBeNull();

    $user->unlock();
    expect($user->status())->toBe(UserStatus::Active);
});
