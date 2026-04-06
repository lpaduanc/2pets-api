<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pet_dewormings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('product_name', 150);
            $table->date('applied_date');
            $table->date('next_date')->nullable();
            $table->decimal('weight_at_application', 6, 2)->nullable();
            $table->foreignId('veterinarian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'applied_date']);
            $table->index('next_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_dewormings');
    }
};
