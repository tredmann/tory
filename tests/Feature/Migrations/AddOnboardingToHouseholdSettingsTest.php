<?php

use App\Models\HouseholdSettings;
use App\Models\Item;
use Illuminate\Support\Facades\DB;

function runOnboardingMigration(bool $withItem): object
{
    $migration = require glob(database_path('migrations/*_add_onboarding_to_household_settings_table.php'))[0];

    $migration->down();

    if ($withItem) {
        Item::factory()->create();
    }

    $migration->up();

    return DB::table('household_settings')->first();
}

it('marks the onboarding done for a household that already has items', function (): void {
    HouseholdSettings::current();
    DB::beginTransaction();

    try {
        $row = runOnboardingMigration(withItem: true);

        expect($row->content_language_confirmed_at)->not->toBeNull()
            ->and($row->onboarding_finished_at)->not->toBeNull();
    } finally {
        DB::rollBack();
    }
});

it('leaves the onboarding open for a household without items', function (): void {
    HouseholdSettings::current();
    DB::beginTransaction();

    try {
        $row = runOnboardingMigration(withItem: false);

        expect($row->content_language_confirmed_at)->toBeNull()
            ->and($row->onboarding_finished_at)->toBeNull();
    } finally {
        DB::rollBack();
    }
});
