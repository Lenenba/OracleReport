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
        Schema::create('object_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20);
            $table->string('object_name', 150);
            $table->string('table_name', 150)->nullable();
            $table->json('fields')->nullable();
            $table->unsignedInteger('field_count')->default(0);
            $table->unsignedBigInteger('source_mtime')->nullable();
            $table->string('source_path', 255)->nullable();
            $table->timestamps();

            $table->unique(['environment', 'object_name']);
            $table->index(['environment', 'object_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('object_mappings');
    }
};
