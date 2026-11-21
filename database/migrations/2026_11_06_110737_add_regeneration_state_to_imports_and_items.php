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
            Schema::table($name, function (Blueprint $table): void {
                $table->timestamp('regenerating_at')->nullable();
                $table->text('regeneration_error')->nullable();
                $table->string('regeneration_failure_reason')->nullable();
                $table->string('regeneration_failure_provider')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['imports', 'items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['regenerating_at', 'regeneration_error', 'regeneration_failure_reason', 'regeneration_failure_provider']);
            });
        }
    }
};
