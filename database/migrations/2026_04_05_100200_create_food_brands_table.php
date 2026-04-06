<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('food_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('dry'); // dry, wet, natural, therapeutic
            $table->string('species_target')->nullable(); // dog, cat, all
            $table->timestamps();

            $table->index('name');
            $table->index('type');
            $table->index('species_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_brands');
    }
};
