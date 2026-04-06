<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pathologies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('species')->nullable(); // null = all species
            $table->string('category')->nullable(); // cardiac, neurological, metabolic, etc.
            $table->timestamps();

            $table->index('name');
            $table->index('species');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pathologies');
    }
};
