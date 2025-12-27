<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->onDelete('cascade');
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('appointment_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('medical_record_id')->nullable()->constrained()->onDelete('set null');

            $table->date('prescription_date');
            $table->date('valid_until')->nullable();

            // Medications as JSON array
            // [{name, dosage, frequency, duration, instructions}]
            $table->json('medications');

            $table->text('general_instructions')->nullable();
            $table->text('warnings')->nullable();
            $table->boolean('is_controlled')->default(false);

            $table->timestamps();

            // Indexes
            $table->index(['pet_id', 'prescription_date']);
            $table->index('professional_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
