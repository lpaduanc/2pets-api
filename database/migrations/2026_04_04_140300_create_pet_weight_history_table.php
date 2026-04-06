<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pet_weight_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->decimal('weight', 6, 2);
            $table->dateTime('measured_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_weight_history');
    }
};
