<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('gives users that existed before the migration the German locale', function () {
    $migration = require glob(database_path('migrations/*_add_locale_to_users_table.php'))[0];

    DB::beginTransaction();

    try {
        $migration->down();

        $user = User::factory()->make();
        $id = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $id,
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'x',
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        expect(DB::table('users')->where('id', $id)->value('locale'))->toBe('de');
    } finally {
        DB::rollBack();
    }
});

it('leaves the locale empty for users created afterwards', function () {
    $user = User::factory()->create(['locale' => null]);

    expect(DB::table('users')->where('id', $user->id)->value('locale'))->toBeNull();
});
