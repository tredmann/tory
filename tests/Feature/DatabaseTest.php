<?php

use Illuminate\Support\Facades\DB;

test('tests run on the tory_testing Postgres database with pgvector available', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('tory_testing')
        ->and(DB::table('pg_available_extensions')->where('name', 'vector')->exists())->toBeTrue();
});

test('the suite refuses to refresh any database other than tory_testing', function (): void {
    config(['database.connections.pgsql.database' => 'tory']);

    expect(fn () => $this->guardTestingDatabase())->toThrow(RuntimeException::class);
});
