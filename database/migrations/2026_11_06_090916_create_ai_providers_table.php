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
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('vendor');
            $table->text('api_key')->nullable();
            $table->string('key_hint')->nullable();
            $table->string('url')->nullable();
            $table->string('model');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX ai_providers_one_active ON ai_providers (is_active) WHERE is_active');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
