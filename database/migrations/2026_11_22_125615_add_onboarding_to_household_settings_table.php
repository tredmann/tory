<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_settings', function (Blueprint $table): void {
            $table->timestamp('content_language_confirmed_at')->nullable();
            $table->timestamp('onboarding_finished_at')->nullable();
        });

        if (DB::table('items')->exists()) {
            DB::table('household_settings')->update([
                'content_language_confirmed_at' => now(),
                'onboarding_finished_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('household_settings', function (Blueprint $table): void {
            $table->dropColumn(['content_language_confirmed_at', 'onboarding_finished_at']);
        });
    }
};
