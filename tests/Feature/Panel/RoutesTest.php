<?php

use App\Models\Import;
use App\Models\Item;
use App\Models\Spot;
use App\Models\User;
use Filament\Facades\Filament;

test('guests are redirected from the panel root to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

test('the login page is served at the root and registration does not exist', function () {
    $this->get('/login')->assertOk();
    $this->get('/register')->assertNotFound();
});

test('filament assets are published', function () {
    expect(public_path('css/filament/filament/app.css'))->toBeFile();
});

test('record pages answer 404 for a key that is no valid key', function (string $path) {
    $this->actingAs(User::factory()->create());

    $this->get($path)->assertNotFound();
})->with([
    '/items/create',
    '/items/abc',
    '/spots/abc',
    '/spots/abc/edit',
    '/imports/abc',
    '/spots/99999999999999999999',
    '/spots/1',
    '/spots/ABC0',
    '/spots/ABCDE',
    '/spots/ABC%00',
    '/items/1',
    '/imports/1',
    '/items/019a0000-0000-7000-8000-000000000000',
    '/imports/019a0000-0000-7000-8000-000000000000',
]);

test('record pages still open for an existing record', function (string $prefix, string $model) {
    $this->actingAs(User::factory()->create());
    $record = $model::factory()->create();

    $this->get("{$prefix}/{$record->getRouteKey()}")->assertOk();
})->with([
    ['/items', Item::class],
    ['/spots', Spot::class],
    ['/imports', Import::class],
]);

test('the provider page is served at its english address', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/ai-providers')->assertOk();
});

test('old german addresses are gone', function (string $path) {
    $this->actingAs(User::factory()->create());

    $this->get($path)->assertNotFound();
})->with(['/lagerorte', '/gegenstaende', '/importe', '/eigene-ki']);

test('global search is disabled for a signed-in user', function () {
    $this->actingAs(User::factory()->create());

    expect(Filament::isGlobalSearchEnabled())->toBeFalse();
});
