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
        Schema::create('bulk_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spot_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->jsonb('photos');
            $table->jsonb('time_cuts');
            $table->jsonb('cuts');
            $table->jsonb('groups')->nullable();
            $table->jsonb('discarded')->default('[]');
            $table->boolean('grouping_incomplete')->default(false);
            $table->string('failure_reason')->nullable();
            $table->string('failure_provider')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_imports');
    }
};
