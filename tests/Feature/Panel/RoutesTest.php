<?php

use App\Models\Import;
use App\Models\Item;
use App\Models\StorageLocation;
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

test('record pages answer 404 for a key that is no valid id', function (string $path) {
    $this->actingAs(User::factory()->create());

    $this->get($path)->assertNotFound();
})->with([
    '/gegenstaende/create',
    '/gegenstaende/abc',
    '/lagerorte/abc',
    '/lagerorte/abc/edit',
    '/importe/abc',
    '/lagerorte/99999999999999999999',
]);

test('record pages still open for an existing record', function (string $prefix, string $model) {
    $this->actingAs(User::factory()->create());
    $record = $model::factory()->create();

    $this->get("{$prefix}/{$record->getKey()}")->assertOk();
})->with([
    ['/gegenstaende', Item::class],
    ['/lagerorte', StorageLocation::class],
    ['/importe', Import::class],
]);

test('global search is disabled for a signed-in user', function () {
    $this->actingAs(User::factory()->create());

    expect(Filament::isGlobalSearchEnabled())->toBeFalse();
});
