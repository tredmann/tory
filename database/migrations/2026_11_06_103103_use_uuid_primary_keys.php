<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing rows keep their data under a new key. Sessions are cleared in
     * both directions: their payload holds the old user key, which would make
     * Postgres fail on every request of a signed-in browser.
     */
    public function up(): void
    {
        DB::table('sessions')->delete();

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->index();
        });

        foreach (['users', 'imports', 'items'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN new_id uuid NOT NULL DEFAULT uuidv7()");
        }

        DB::statement('ALTER TABLE imports ADD COLUMN new_user_id uuid');
        DB::statement('UPDATE imports SET new_user_id = users.new_id FROM users WHERE imports.user_id = users.id');
        DB::statement('ALTER TABLE imports DROP COLUMN user_id');
        DB::statement('ALTER TABLE imports RENAME COLUMN new_user_id TO user_id');
        DB::statement('ALTER TABLE imports ALTER COLUMN user_id SET NOT NULL');

        foreach (['users', 'imports', 'items'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} DROP COLUMN id");
            DB::statement("ALTER TABLE {$tableName} RENAME COLUMN new_id TO id");
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN id DROP DEFAULT");
            DB::statement("ALTER TABLE {$tableName} ADD PRIMARY KEY (id)");
        }

        Schema::table('imports', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE ai_providers DROP COLUMN id');
        DB::statement('ALTER TABLE ai_providers DROP CONSTRAINT ai_providers_uuid_unique');
        DB::statement('ALTER TABLE ai_providers RENAME COLUMN uuid TO id');
        DB::statement('ALTER TABLE ai_providers ADD PRIMARY KEY (id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sessions')->delete();

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->index();
        });

        foreach (['users', 'imports', 'items'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN new_id bigserial");
        }

        DB::statement('ALTER TABLE imports ADD COLUMN new_user_id bigint');
        DB::statement('UPDATE imports SET new_user_id = users.new_id FROM users WHERE imports.user_id = users.id');
        DB::statement('ALTER TABLE imports DROP COLUMN user_id');
        DB::statement('ALTER TABLE imports RENAME COLUMN new_user_id TO user_id');
        DB::statement('ALTER TABLE imports ALTER COLUMN user_id SET NOT NULL');

        foreach (['users', 'imports', 'items'] as $tableName) {
            DB::statement("ALTER TABLE {$tableName} DROP COLUMN id");
            DB::statement("ALTER TABLE {$tableName} RENAME COLUMN new_id TO id");
            DB::statement("ALTER TABLE {$tableName} ADD PRIMARY KEY (id)");
        }

        Schema::table('imports', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE ai_providers DROP CONSTRAINT ai_providers_pkey');
        DB::statement('ALTER TABLE ai_providers RENAME COLUMN id TO uuid');
        DB::statement('ALTER TABLE ai_providers ADD CONSTRAINT ai_providers_uuid_unique UNIQUE (uuid)');
        DB::statement('ALTER TABLE ai_providers ADD COLUMN id bigserial PRIMARY KEY');
    }
};
