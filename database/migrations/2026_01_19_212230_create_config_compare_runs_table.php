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
        Schema::create('config_compare_runs', function (Blueprint $table) {
            $table->id();
            $table->string('left_label', 60);
            $table->string('right_label', 60);
            $table->string('left_source', 200)->nullable();
            $table->string('right_source', 200)->nullable();
            $table->string('status', 20)->default('completed');
            $table->json('payload');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_compare_runs');
    }
};
