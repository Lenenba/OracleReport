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
        Schema::create('mapping_environments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label', 100)->nullable();
            $table->string('source_path', 255)->nullable();
            $table->unsignedBigInteger('source_mtime')->nullable();
            $table->timestamps();
        });

        Schema::create('mapping_objects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('mapping_object_envs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('mapping_objects')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('mapping_environments')->cascadeOnDelete();
            $table->string('table_name', 150)->nullable();
            $table->unsignedInteger('field_count')->default(0);
            $table->timestamps();

            $table->unique(['object_id', 'environment_id']);
            $table->index(['environment_id', 'object_id']);
            $table->index('table_name');
        });

        Schema::create('mapping_object_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('mapping_objects')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('name_key', 150);
            $table->timestamps();

            $table->unique(['object_id', 'name_key']);
            $table->index(['object_id', 'name_key']);
        });

        Schema::create('mapping_field_envs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_field_id')->constrained('mapping_object_fields')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('mapping_environments')->cascadeOnDelete();
            $table->string('machine_name', 150)->nullable();
            $table->timestamps();

            $table->unique(['object_field_id', 'environment_id']);
            $table->index(['environment_id', 'object_field_id']);
        });

        Schema::create('mapping_object_field_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('mapping_objects')->cascadeOnDelete();
            $table->foreignId('from_environment_id')->constrained('mapping_environments')->cascadeOnDelete();
            $table->foreignId('to_environment_id')->constrained('mapping_environments')->cascadeOnDelete();
            $table->string('from_table', 150)->nullable();
            $table->string('to_table', 150)->nullable();
            $table->string('field_name', 150)->nullable();
            $table->string('from_column', 150);
            $table->string('to_column', 150);
            $table->timestamps();

            $table->index(
                ['from_environment_id', 'to_environment_id', 'object_id'],
                'mapping_obj_field_maps_env_object_idx'
            );
            $table->index(
                ['from_environment_id', 'to_environment_id', 'from_table'],
                'mapping_obj_field_maps_from_table_idx'
            );
            $table->index(
                ['from_environment_id', 'to_environment_id', 'from_column'],
                'mapping_obj_field_maps_from_column_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mapping_object_field_maps');
        Schema::dropIfExists('mapping_field_envs');
        Schema::dropIfExists('mapping_object_fields');
        Schema::dropIfExists('mapping_object_envs');
        Schema::dropIfExists('mapping_objects');
        Schema::dropIfExists('mapping_environments');
    }
};
