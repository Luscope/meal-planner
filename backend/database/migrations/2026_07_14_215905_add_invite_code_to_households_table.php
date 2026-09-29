<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->string('invite_code')->nullable()->unique()->after('name');
        });

        DB::table('households')->whereNull('invite_code')->orderBy('id')->pluck('id')->each(function ($id) {
            DB::table('households')->where('id', $id)->update([
                'invite_code' => Str::upper(Str::random(8)),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('invite_code');
        });
    }
};
