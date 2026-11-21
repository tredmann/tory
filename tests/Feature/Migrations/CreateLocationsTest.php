<?php

use Illuminate\Support\Facades\DB;

function createLocationsMigration(): object
{
    return require glob(database_path('migrations/*_create_locations_table.php'))[0];
}

it('moves existing spots to a location named Allgemein', function (): void {
    $migration = createLocationsMigration();

    DB::beginTransaction();

    try {
        $migration->down();

        foreach (['ABCD', 'EFGH'] as $code) {
            DB::table('spots')->insert([
                'code' => $code,
                'name' => "Spot {$code}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();

        $location = DB::table('locations')->sole();

        expect($location->name)->toBe('Allgemein')
            ->and(DB::table('spots')->pluck('location_id')->unique()->all())->toBe([$location->id]);
    } finally {
        DB::rollBack();
    }
});

it('creates no location for an installation without spots', function (): void {
    $migration = createLocationsMigration();

    DB::beginTransaction();

    try {
        $migration->down();
        $migration->up();

        expect(DB::table('locations')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
});
