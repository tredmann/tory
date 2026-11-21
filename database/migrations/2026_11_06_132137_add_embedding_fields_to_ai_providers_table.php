<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->string('model')->nullable()->change();
            $table->string('embedding_model')->nullable();
            $table->float('search_min_similarity')->nullable();
            $table->boolean('is_active_for_search')->default(false);
            $table->text('search_failure')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX ai_providers_one_search_active ON ai_providers (is_active_for_search) WHERE is_active_for_search');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_providers_one_search_active');

        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn(['embedding_model', 'search_min_similarity', 'is_active_for_search', 'search_failure']);
        });
    }
};
