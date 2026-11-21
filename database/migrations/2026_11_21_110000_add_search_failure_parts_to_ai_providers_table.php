<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->string('search_failure_reason')->nullable()->after('search_failure');
            $table->unsignedInteger('search_failure_dimensions')->nullable()->after('search_failure_reason');
            $table->boolean('search_failure_generic')->default(false)->after('search_failure_dimensions');
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn(['search_failure_reason', 'search_failure_dimensions', 'search_failure_generic']);
        });
    }
};
