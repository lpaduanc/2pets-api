<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('species'); // dog, cat
            $table->string('breed')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable(); // male, female
            $table->decimal('weight', 5, 2)->nullable();
            $table->string('color')->nullable();
            $table->boolean('neutered')->default(false);
            $table->string('blood_type')->nullable();
            $table->text('allergies')->nullable();
            $table->text('chronic_diseases')->nullable();
            $table->text('current_medications')->nullable();
            $table->json('temperament')->nullable();
            $table->text('behavior_notes')->nullable();
            $table->json('social_with')->nullable();
            $table->text('notes')->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pets');
    }
};
