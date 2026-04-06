<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pet_medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('dosage', 100);
            $table->string('frequency', 100);
            $table->date('start_date');
            $table->date('end_date')->nullable()->comment('Null = uso contínuo');
            $table->foreignId('prescribed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['pet_id', 'active']);
            $table->index('prescribed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_medications');
    }
};
