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
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('cuisine')->nullable()->index();
            $table->text('description')->nullable();
            $table->json('instructions');
            $table->unsignedInteger('servings')->default(1);
            $table->unsignedInteger('prep_time_minutes')->nullable();
            $table->unsignedInteger('cook_time_minutes')->nullable();
            $table->unsignedInteger('calories_per_serving')->nullable();
            $table->decimal('protein_per_serving_g', 6, 2)->nullable();
            $table->decimal('carbs_per_serving_g', 6, 2)->nullable();
            $table->decimal('fat_per_serving_g', 6, 2)->nullable();
            $table->string('source_type')->default('text');
            $table->string('source_url')->nullable();
            $table->string('image_path')->nullable();
            $table->json('raw_import_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
