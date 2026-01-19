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
        Schema::create('config_compare_entries', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 20);
            $table->string('source_label', 60);
            $table->string('target_label', 60);
            $table->longText('input_sql');
            $table->longText('output_sql');
            $table->json('replacements')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_compare_entries');
    }
};
