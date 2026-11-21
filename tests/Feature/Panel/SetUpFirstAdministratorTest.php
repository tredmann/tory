<?php

use App\Enums\Language;
use App\Filament\Auth\SetUpFirstAdministrator;
use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function firstAdministratorForm(): array
{
    return ['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'ein-langes-passwort', 'passwordConfirmation' => 'ein-langes-passwort'];
}

test('the setup page asks for the first administrator', function () {
    $this->get('/setup')
        ->assertOk()
        ->assertSeeText('Willkommen bei Tory')
        ->assertSeeText('Lege den ersten Administrator an.')
        ->assertSeeText('Administrator anlegen');
});

test('the setup page follows the browser language on a fresh install', function () {
    $this->withHeader('Accept-Language', 'en')
        ->get('/setup')
        ->assertOk()
        ->assertSeeText('Welcome to Tory')
        ->assertDontSeeText('Willkommen bei Tory');
});

test('setting up logs the new administrator in', function () {
    Livewire::test(SetUpFirstAdministrator::class)
        ->fillForm(firstAdministratorForm())
        ->call('create')
        ->assertRedirect(filament()->getUrl());

    $user = User::firstWhere('email', 'anna@example.com');
    $this->assertAuthenticatedAs($user);
    expect($user->isAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

test('the setup page is gone once a user exists', function () {
    User::factory()->create();

    $this->get('/setup')->assertNotFound();
});

test('a submit after someone else set up creates nobody and goes to the login', function () {
    $page = Livewire::test(SetUpFirstAdministrator::class)->fillForm(firstAdministratorForm());
    User::factory()->create();

    $page->call('create')->assertRedirect(filament()->getLoginUrl());

    expect(User::query()->count())->toBe(1);
    $this->assertGuest();
});

test('the setup needs a confirmed password and a valid email', function () {
    Livewire::test(SetUpFirstAdministrator::class)
        ->fillForm([...firstAdministratorForm(), 'email' => 'keine-adresse', 'passwordConfirmation' => 'anders'])
        ->call('create')
        ->assertHasFormErrors(['email', 'password']);
});

test('in production the setup refuses a password shorter than 15 characters', function () {
    $page = Livewire::test(SetUpFirstAdministrator::class)
        ->fillForm([...firstAdministratorForm(), 'password' => 'nurkleinbuchst', 'passwordConfirmation' => 'nurkleinbuchst']);

    app()->detectEnvironment(fn (): string => 'production');
    Http::preventStrayRequests();
    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('')]);

    $page->call('create')
        ->assertHasFormErrors(['password' => 'Das Feld Passwort muss mindestens 15 Zeichen lang sein.']);

    $this->assertGuest();
});

test('an empty installation sends every panel page to the setup', function (string $path) {
    $this->get($path)->assertRedirect(route('filament.admin.setup'));
})->with(['/', '/login', '/items', '/invite/irgendwas']);

test('a user created from the command line closes the setup', function () {
    User::factory()->unverified()->create();

    $this->get('/login')->assertOk();
    $this->get('/setup')->assertNotFound();
});

test('the api is not redirected on an empty installation', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

test('setting up in an English browser makes English the content language', function () {
    app()->setLocale('en');

    Livewire::test(SetUpFirstAdministrator::class)
        ->fillForm(firstAdministratorForm())
        ->call('create');

    expect(HouseholdSettings::current()->content_language)->toBe(Language::English);
});
