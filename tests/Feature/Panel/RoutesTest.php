<?php

test('guests are redirected from the panel root to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

test('the login and registration pages are served at the root', function () {
    $this->get('/login')->assertOk();
    $this->get('/register')->assertOk();
});

test('filament assets are published', function () {
    expect(public_path('css/filament/filament/app.css'))->toBeFile();
});
