<?php

use App\Enums\Language;
use App\Models\HouseholdSettings;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Route::middleware(['web'])->get('/_locale', fn () => app()->getLocale());
});

it("uses the user's locale", function () {
    $this->actingAs(User::factory()->create(['locale' => Language::English]))
        ->withHeader('Accept-Language', 'de')
        ->get('/_locale')
        ->assertSeeText('en');
});

it('follows the browser for a user without a locale', function () {
    $this->actingAs(User::factory()->create(['locale' => null]));

    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')->get('/_locale')->assertSeeText('de');
    $this->withHeader('Accept-Language', 'fr')->get('/_locale')->assertSeeText('en');
});

it('uses the household language for guests', function () {
    User::factory()->create();

    $this->withHeader('Accept-Language', 'en')->get('/_locale')->assertSeeText('de');

    HouseholdSettings::current()->changeContentLanguage(Language::English);

    $this->withHeader('Accept-Language', 'de')->get('/_locale')->assertSeeText('en');
});

it('follows the browser on a fresh install', function () {
    $this->withHeader('Accept-Language', 'en')->get('/_locale')->assertSeeText('en');
    $this->withHeader('Accept-Language', 'de')->get('/_locale')->assertSeeText('de');
});

it('answers the token endpoint in the household language', function () {
    $user = User::factory()->create();

    $this->withHeader('Accept-Language', 'en')
        ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'iPhone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => __('auth.failed', locale: 'de')]);
});

it("answers API requests in the token owner's language", function () {
    Sanctum::actingAs(User::factory()->create(['locale' => Language::English]));
    $item = Item::factory()->create();

    $this->patchJson("/api/v1/items/{$item->id}", ['title' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title' => 'The title field is required.']);
});
