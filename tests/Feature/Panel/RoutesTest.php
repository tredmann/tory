<?php

use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use Filament\Facades\Filament;

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

test('record pages answer 404 for a key that is no valid key', function (string $path) {
    $this->actingAs(User::factory()->create());

    $this->get($path)->assertNotFound();
})->with([
    '/gegenstaende/create',
    '/gegenstaende/abc',
    '/spots/abc',
    '/spots/abc/edit',
    '/importe/abc',
    '/spots/99999999999999999999',
    '/spots/1',
    '/spots/ABC0',
    '/spots/ABCDE',
    '/spots/ABC%00',
    '/gegenstaende/1',
    '/importe/1',
    '/gegenstaende/019a0000-0000-7000-8000-000000000000',
    '/importe/019a0000-0000-7000-8000-000000000000',
]);

test('record pages still open for an existing record', function (string $prefix, string $model) {
    $this->actingAs(User::factory()->create());
    $record = $model::factory()->create();

    $this->get("{$prefix}/{$record->getRouteKey()}")->assertOk();
})->with([
    ['/gegenstaende', Item::class],
    ['/spots', Spot::class],
    ['/importe', Import::class],
]);

test('global search is disabled for a signed-in user', function () {
    $this->actingAs(User::factory()->create());

    expect(Filament::isGlobalSearchEnabled())->toBeFalse();
});
