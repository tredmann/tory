<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(function () {
    TrustProxies::flushState();
});

test('self service accounts are on by default', function () {
    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect($panel->hasRegistration())->toBeTrue()
        ->and($panel->hasPasswordReset())->toBeTrue();
});

test('turning self service off removes registration and password reset but not the login', function () {
    config(['auth.self_service' => false]);

    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect($panel->hasRegistration())->toBeFalse()
        ->and($panel->hasPasswordReset())->toBeFalse()
        ->and($panel->hasLogin())->toBeTrue()
        ->and($panel->hasEmailVerification())->toBeTrue();
});

test('a trusted proxy makes generated urls https', function () {
    config(['app.trusted_proxies' => '10.0.0.0/8, 127.0.0.1']);
    (new AppServiceProvider(app()))->boot();

    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/login')->assertOk();

    expect($response->getContent())->toMatch('#"https://[^"]+/css/filament/#');
});

test('the forwarded scheme is ignored without a trusted proxy', function () {
    (new AppServiceProvider(app()))->boot();

    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/login')->assertOk();

    expect($response->getContent())->toMatch('#"http://[^"]+/css/filament/#');
});
