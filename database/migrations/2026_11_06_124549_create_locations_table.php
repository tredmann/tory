<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('spots', fn (Blueprint $table) => $table->uuid('location_id')->nullable());

        if (DB::table('spots')->exists()) {
            $id = (string) Str::uuid7();

            DB::table('locations')->insert([
                'id' => $id,
                'name' => 'Allgemein',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('spots')->update(['location_id' => $id]);
        }

        Schema::table('spots', function (Blueprint $table): void {
            $table->uuid('location_id')->nullable(false)->change();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::dropIfExists('locations');
    }
};
