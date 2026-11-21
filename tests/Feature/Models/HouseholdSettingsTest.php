<?php

use App\Enums\Language;
use App\Models\HouseholdSettings;

it('returns the single row created by the migration with German', function () {
    expect(HouseholdSettings::current()->content_language)->toBe(Language::German)
        ->and(HouseholdSettings::query()->count())->toBe(1);
});

it('changes the content language', function () {
    HouseholdSettings::current()->changeContentLanguage(Language::English);

    expect(HouseholdSettings::current()->content_language)->toBe(Language::English)
        ->and(HouseholdSettings::query()->count())->toBe(1);
});
