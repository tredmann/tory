<?php

use App\Models\User;
use Filament\Enums\ThemeMode;

test('the chosen theme is saved on the account', function (string $value, ThemeMode $saved) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/theme', ['theme' => $value])
        ->assertNoContent();

    expect($user->fresh()->theme_mode)->toBe($saved);
})->with([
    ['light', ThemeMode::Light],
    ['dark', ThemeMode::Dark],
    ['system', ThemeMode::System],
]);

test('saving a theme only changes the account that is logged in', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['theme_mode' => ThemeMode::Light]);

    $this->actingAs($user)
        ->postJson('/theme', ['theme' => 'dark', 'id' => $other->id, 'user_id' => $other->id])
        ->assertNoContent();

    expect($other->fresh()->theme_mode)->toBe(ThemeMode::Light);
});

test('a value that is no theme is rejected with 422 and changes nothing', function (array $payload) {
    $user = User::factory()->create(['theme_mode' => ThemeMode::Dark]);

    $this->actingAs($user)
        ->postJson('/theme', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('theme');

    expect($user->fresh()->theme_mode)->toBe(ThemeMode::Dark);
})->with([
    'unknown value' => [['theme' => 'neon']],
    'missing value' => [[]],
]);

test('a guest cannot save a theme and gets 401', function () {
    $this->postJson('/theme', ['theme' => 'dark'])->assertUnauthorized();
});

test('the account theme replaces the browser value before filament reads it', function () {
    $this->withoutVite();

    $this->actingAs(User::factory()->create(['theme_mode' => ThemeMode::Dark]))
        ->get('/')
        ->assertOk()
        ->assertSeeInOrder(["localStorage.setItem('filament:theme:mode', 'dark')", 'const loadDarkMode'], false);
});

test('an account without a theme leaves the browser value alone but can save one', function () {
    $this->withoutVite();

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertDontSee("localStorage.setItem('filament:theme:mode'", false)
        ->assertSee('theme-changed', false);
});

test('the login page leaves the browser value alone and saves nothing', function () {
    $this->withoutVite();

    $this->get('/login')
        ->assertOk()
        ->assertDontSee("localStorage.setItem('filament:theme:mode'", false)
        ->assertDontSee('theme-changed', false);
});
