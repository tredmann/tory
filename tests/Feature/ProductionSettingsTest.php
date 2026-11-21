<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

afterEach(function (): void {
    TrustProxies::flushState();
});

test('the panel has neither registration nor password reset', function (): void {
    $panel = new AdminPanelProvider(app())->panel(Panel::make());

    expect($panel->hasRegistration())->toBeFalse()
        ->and($panel->hasPasswordReset())->toBeFalse()
        ->and($panel->hasLogin())->toBeTrue()
        ->and($panel->hasEmailVerification())->toBeTrue();
});

test('a trusted proxy makes generated urls https', function (): void {
    config(['app.trusted_proxies' => '10.0.0.0/8, 127.0.0.1']);
    new AppServiceProvider(app())->boot();
    User::factory()->create();

    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/login')->assertOk();

    expect($response->getContent())->toMatch('#"https://[^"]+/css/filament/#')
        ->and($response->getContent())->toMatch('#rel="icon"[^>]+href="https://[^"]+/images/tory-mark\.svg"#');
});

test('the forwarded scheme is ignored without a trusted proxy', function (): void {
    new AppServiceProvider(app())->boot();
    User::factory()->create();

    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/login')->assertOk();

    expect($response->getContent())->toMatch('#"http://[^"]+/css/filament/#');
});

/**
 * Answers the Pwned Passwords range request; the given password is reported as leaked.
 */
function fakePwnedPasswords(?string $leaked = null): void
{
    Http::preventStrayRequests();

    $body = $leaked === null ? '' : substr(strtoupper(sha1($leaked)), 5).':42';

    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response($body)]);
}

function passwordPasses(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => Password::default()])->passes();
}

test('in production a password of 15 characters passes without any character class', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    fakePwnedPasswords();

    expect(passwordPasses('nurkleinbuchsta'))->toBeTrue();
});

test('in production a password shorter than 15 characters fails', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    fakePwnedPasswords();

    expect(passwordPasses('nurkleinbuchst'))->toBeFalse();
});

test('in production a leaked password fails', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    fakePwnedPasswords(leaked: 'ein-geleaktes-passwort');

    expect(passwordPasses('ein-geleaktes-passwort'))->toBeFalse();
});

test('outside production a password of 8 characters passes', function (): void {
    expect(passwordPasses('achtzeic'))->toBeTrue();
});
