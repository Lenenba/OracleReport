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
        Schema::table('config_compare_entries', function (Blueprint $table) {
            $table->json('issues')->nullable()->after('replacements');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('config_compare_entries', function (Blueprint $table) {
            $table->dropColumn('issues');
        });
    }
};
