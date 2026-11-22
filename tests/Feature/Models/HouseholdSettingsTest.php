<?php

use App\Enums\Language;
use App\Models\HouseholdSettings;

it('returns the single row created by the migration with German', function (): void {
    expect(HouseholdSettings::current()->content_language)->toBe(Language::German)
        ->and(HouseholdSettings::query()->count())->toBe(1);
});

it('changes the content language', function (): void {
    HouseholdSettings::current()->changeContentLanguage(Language::English);

    expect(HouseholdSettings::current()->content_language)->toBe(Language::English)
        ->and(HouseholdSettings::query()->count())->toBe(1);
});

it('starts neither confirmed nor finished', function (): void {
    expect(HouseholdSettings::current()->content_language_confirmed_at)->toBeNull()
        ->and(HouseholdSettings::current()->onboarding_finished_at)->toBeNull();
});

test('confirming and finishing twice keep the first time', function (): void {
    $settings = HouseholdSettings::current();
    $settings->confirmContentLanguage();
    $settings->finishOnboarding();
    $confirmed = HouseholdSettings::current()->content_language_confirmed_at;
    $finished = HouseholdSettings::current()->onboarding_finished_at;

    expect($confirmed)->not->toBeNull()->and($finished)->not->toBeNull();

    $this->travel(1)->hour();
    $settings = HouseholdSettings::current();
    $settings->confirmContentLanguage();
    $settings->finishOnboarding();

    expect(HouseholdSettings::current()->content_language_confirmed_at->equalTo($confirmed))->toBeTrue()
        ->and(HouseholdSettings::current()->onboarding_finished_at->equalTo($finished))->toBeTrue();
});
