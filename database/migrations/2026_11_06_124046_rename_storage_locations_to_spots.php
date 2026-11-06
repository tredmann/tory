<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('storage_locations', 'spots');

        Schema::table('items', fn ($table) => $table->renameColumn('storage_location_id', 'spot_id'));
        Schema::table('imports', fn ($table) => $table->renameColumn('storage_location_id', 'spot_id'));
        Schema::table('users', fn ($table) => $table->renameColumn('last_storage_location_id', 'last_spot_id'));

        $this->renameDatabaseObjects('storage_location', 'spot', 'storage_locations', 'spots', 'spots');
    }

    public function down(): void
    {
        $this->renameDatabaseObjects('spot', 'storage_location', 'spots', 'storage_locations', 'spots');

        Schema::table('users', fn ($table) => $table->renameColumn('last_spot_id', 'last_storage_location_id'));
        Schema::table('imports', fn ($table) => $table->renameColumn('spot_id', 'storage_location_id'));
        Schema::table('items', fn ($table) => $table->renameColumn('spot_id', 'storage_location_id'));

        Schema::rename('spots', 'storage_locations');
    }

    /**
     * Renames constraints and the sequence, which keep their old names when tables and columns are renamed.
     * The table is named $toTable already (up) or still (down) while this runs.
     */
    private function renameDatabaseObjects(string $from, string $to, string $fromTable, string $toTable, string $table): void
    {
        DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$fromTable}_pkey TO {$toTable}_pkey");
        DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$fromTable}_code_unique TO {$toTable}_code_unique");
        DB::statement("ALTER TABLE items RENAME CONSTRAINT items_{$from}_id_foreign TO items_{$to}_id_foreign");
        DB::statement("ALTER TABLE imports RENAME CONSTRAINT imports_{$from}_id_foreign TO imports_{$to}_id_foreign");
        DB::statement("ALTER TABLE users RENAME CONSTRAINT users_last_{$from}_id_foreign TO users_last_{$to}_id_foreign");
        DB::statement("ALTER SEQUENCE {$fromTable}_id_seq RENAME TO {$toTable}_id_seq");
    }
};
