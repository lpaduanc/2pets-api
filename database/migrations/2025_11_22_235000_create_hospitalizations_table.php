<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hospitalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->onDelete('cascade');
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->date('admission_date');
            $table->date('discharge_date')->nullable();
            $table->string('reason');
            $table->enum('status', ['active', 'discharged', 'transferred'])->default('active');
            $table->json('daily_notes')->nullable();
            $table->json('medications')->nullable();
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitalizations');
    }
};
