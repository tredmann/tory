<?php

use App\Enums\Language;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Models\Import;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Livewire\Livewire;

/**
 * Livewire::test() skips the middleware, so the locale SetLocale would pick is set by hand.
 */
function userWithLocale(Language $language): User
{
    $user = User::factory()->create();
    $user->forceFill(['locale' => $language])->save();
    app()->setLocale($language->value);

    return $user;
}

beforeEach(fn () => Filament::setCurrentPanel('admin'));

test('a German user is offered English in the user menu', function () {
    $this->actingAs(userWithLocale(Language::German));

    Livewire::test(Topbar::class)->assertSee('English')->assertDontSee('Deutsch');
});

test('an English user is offered German in the user menu', function () {
    $this->actingAs(userWithLocale(Language::English));

    Livewire::test(Topbar::class)->assertSee('Deutsch')->assertDontSee('English');
});

test('the language action saves the other language on the user', function (Language $from, Language $to) {
    $user = userWithLocale($from);
    $this->actingAs($user);

    Livewire::test(Topbar::class)->callAction('language');

    expect($user->fresh()->locale)->toBe($to);
})->with([
    'German to English' => [Language::German, Language::English],
    'English to German' => [Language::English, Language::German],
]);

test('table dates follow the interface language', function (Language $language, string $expected) {
    $this->actingAs(userWithLocale($language));
    Import::factory()->completed()->create(['created_at' => '2026-11-05 14:30:00']);

    Livewire::test(ListImports::class)->assertSee($expected);
})->with([
    'German' => [Language::German, '05.11.2026 14:30'],
    'English' => [Language::English, '5 Nov 2026, 14:30'],
]);
