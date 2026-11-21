<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('renaming keeps spots and the items on them', function (): void {
    $migration = require glob(database_path('migrations/*_rename_storage_locations_to_spots.php'))[0];

    $createLocations = require glob(database_path('migrations/*_create_locations_table.php'))[0];

    DB::beginTransaction();

    try {
        $createLocations->down();
        $migration->down();

        $id = DB::table('storage_locations')->insertGetId([
            'code' => 'ABCD',
            'name' => 'Schrank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('items')->insert([
            'id' => (string) Str::uuid7(),
            'title' => 'Hammer',
            'storage_location_id' => $id,
            'photos' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();
        $createLocations->up();

        $spot = DB::table('spots')->where('id', $id)->first();

        expect($spot->code)->toBe('ABCD')
            ->and($spot->name)->toBe('Schrank')
            ->and(DB::table('items')->where('title', 'Hammer')->value('spot_id'))->toBe($id);
    } finally {
        DB::rollBack();
    }
});
