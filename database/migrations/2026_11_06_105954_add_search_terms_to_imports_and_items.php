<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['imports', 'items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->jsonb('search_terms')->default('[]');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['imports', 'items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('search_terms');
            });
        }
    }
};
