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
        Schema::dropIfExists('professionals');

        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('professional_type'); // vet, clinic, laboratory, petshop, pet_hotel, grooming, training

            // Common business fields
            $table->string('business_name')->nullable();
            $table->string('cnpj', 18)->nullable()->unique();
            $table->text('specialties')->nullable(); // JSON array
            $table->time('opening_hours')->nullable();
            $table->time('closing_hours')->nullable();
            $table->json('working_days')->nullable(); // [1,2,3,4,5] for Mon-Fri
            $table->text('description')->nullable();

            // Vet-specific fields
            $table->string('crmv')->nullable()->unique();
            $table->string('crmv_state', 2)->nullable();
            $table->string('university')->nullable();
            $table->integer('graduation_year')->nullable();
            $table->text('courses')->nullable(); // JSON array of additional courses
            $table->integer('experience_years')->nullable();

            // Clinic/Laboratory specific
            $table->foreignId('technical_responsible_id')->nullable()->constrained('users')->onDelete('set null');

            // Service area
            $table->integer('service_radius_km')->nullable(); // For mobile services

            // Additional services/products
            $table->text('services_offered')->nullable(); // JSON array
            $table->text('products_sold')->nullable(); // JSON array (for petshops)

            $table->timestamps();

            // Indexes for performance
            $table->index(['professional_type', 'user_id']);
            $table->index('technical_responsible_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professionals');
    }
};
