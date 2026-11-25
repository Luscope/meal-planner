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
        Schema::table('recipes', function (Blueprint $table) {
            // NULL = not classified yet; false = complete meal on its own;
            // true = usually served with a side dish.
            $table->boolean('needs_side')->nullable();
            // Short free-text pairing hint, e.g. "Kartoffelpüree, Reis oder Brot".
            // Only set when needs_side is true.
            $table->string('side_suggestion')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn(['needs_side', 'side_suggestion']);
        });
    }
};
