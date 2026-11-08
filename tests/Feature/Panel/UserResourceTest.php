<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('a member cannot open the user list', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/users')->assertForbidden();
});

test('the list shows role and status', function () {
    $invited = User::invite('Anna', 'anna@example.com', UserRole::Member);
    $locked = User::factory()->locked()->create();

    Livewire::test(ManageUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $invited, $locked])
        ->assertSee(['Administrator', 'Eingeladen', 'Gesperrt']);
});

test('creating a user shows the invite link once', function () {
    Livewire::test(ManageUsers::class)
        ->callAction('create', ['name' => 'Anna', 'email' => 'anna@example.com', 'role' => UserRole::Member->value])
        ->assertActionMounted('showInviteLink')
        ->assertMountedActionModalSee(['Einladungslink', '/invite/']);

    $anna = User::firstWhere('email', 'anna@example.com');
    expect($anna->status())->toBe(UserStatus::Invited)
        ->and($anna->role)->toBe(UserRole::Member);
});

test('an existing email is refused', function () {
    User::factory()->create(['email' => 'anna@example.com']);

    Livewire::test(ManageUsers::class)
        ->callAction('create', ['name' => 'Anna', 'email' => 'anna@example.com', 'role' => UserRole::Member->value])
        ->assertHasFormErrors(['email' => 'unique']);

    expect(User::where('email', 'anna@example.com')->count())->toBe(1);
});

test('a new link invalidates the old one', function () {
    $anna = User::factory()->create();
    $old = Str::afterLast($anna->issueInviteLink(), '/invite/');

    Livewire::test(ManageUsers::class)
        ->callTableAction('issueInvite', $anna)
        ->assertActionMounted('showInviteLink');

    expect(User::findByInviteToken($old))->toBeNull();
});

test('an administrator locks and unlocks a user', function () {
    $anna = User::factory()->create();

    Livewire::test(ManageUsers::class)->callTableAction('lock', $anna);
    expect($anna->fresh()->isLocked())->toBeTrue();

    Livewire::test(ManageUsers::class)->callTableAction('unlock', $anna);
    expect($anna->fresh()->isLocked())->toBeFalse();
});

test('editing changes name, email and role', function () {
    $anna = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $anna, ['name' => 'Anna B.', 'email' => 'anna.b@example.com', 'role' => UserRole::Admin->value]);

    expect($anna->fresh())
        ->name->toBe('Anna B.')
        ->email->toBe('anna.b@example.com')
        ->role->toBe(UserRole::Admin);
});

test('an administrator cannot lock, demote or re-invite themselves', function () {
    Livewire::test(ManageUsers::class)
        ->assertTableActionHidden('lock', $this->admin)
        ->assertTableActionHidden('issueInvite', $this->admin)
        ->mountTableAction('edit', $this->admin)
        ->assertFormFieldIsDisabled('role');
});

test('an administrator cannot demote themselves through the edit action', function () {
    Livewire::test(ManageUsers::class)
        ->callTableAction('edit', $this->admin, ['name' => $this->admin->name, 'email' => $this->admin->email, 'role' => UserRole::Member->value]);

    expect($this->admin->fresh()->role)->toBe(UserRole::Admin);
});

test('there is no delete', function () {
    Livewire::test(ManageUsers::class)
        ->assertTableActionDoesNotExist('delete');
});
