<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->onDelete('cascade');
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('appointment_id')->nullable()->constrained()->onDelete('set null');
            $table->date('record_date');

            // Vital Signs
            $table->decimal('weight', 5, 2)->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->integer('heart_rate')->nullable();
            $table->integer('respiratory_rate')->nullable();

            // SOAP Format
            $table->text('subjective')->nullable(); // What the owner reports
            $table->text('objective')->nullable(); // What the vet observes
            $table->text('assessment')->nullable(); // Diagnosis
            $table->text('plan')->nullable(); // Treatment plan

            // Additional fields
            $table->json('symptoms')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment_plan')->nullable();
            $table->json('prescriptions')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['pet_id', 'record_date']);
            $table->index('professional_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};
