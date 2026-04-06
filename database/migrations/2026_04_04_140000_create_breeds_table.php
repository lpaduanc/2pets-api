<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('breeds', function (Blueprint $table) {
            $table->id();
            $table->string('species', 20)->index();
            $table->string('name', 100);
            $table->string('size_category', 20)->nullable();
            $table->smallInteger('life_expectancy_years')->nullable();
            $table->timestamps();

            $table->unique(['species', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breeds');
    }
};
