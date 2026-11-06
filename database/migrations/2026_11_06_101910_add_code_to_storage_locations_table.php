<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Random\Randomizer;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->string('code', 4)->nullable()->after('id');
        });

        $randomizer = new Randomizer;
        $usedCodes = [];

        foreach (DB::table('storage_locations')->pluck('id') as $id) {
            do {
                $code = $randomizer->getBytesFromString('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', 4);
            } while (isset($usedCodes[$code]));

            $usedCodes[$code] = true;

            DB::table('storage_locations')->where('id', $id)->update(['code' => $code]);
        }

        Schema::table('storage_locations', function (Blueprint $table) {
            $table->string('code', 4)->nullable(false)->change();
            $table->unique('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
