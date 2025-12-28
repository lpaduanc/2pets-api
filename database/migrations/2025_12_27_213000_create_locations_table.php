<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->string('name'); // e.g., "Main Clinic", "Downtown Branch"
            $table->string('address');
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip_code', 10);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('opening_hours')->nullable(); // Per location hours
            $table->json('working_days')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('amenities')->nullable(); // Parking, wheelchair access, etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'is_active']);
        });

        // Add location_id to existing tables
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('professional_id')->constrained();
            $table->index('location_id');
        });

        Schema::table('availabilities', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('professional_id')->constrained();
            $table->index('location_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('professional_id')->constrained();
            $table->index('location_id');
        });

        Schema::table('blocked_times', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('professional_id')->constrained();
            $table->index('location_id');
        });

        Schema::create('location_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->foreignId('staff_id')->constrained('users')->onDelete('cascade');
            $table->enum('role', ['manager', 'veterinarian', 'assistant', 'receptionist'])->default('assistant');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'staff_id']);
            $table->index(['location_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_staff');
        
        Schema::table('blocked_times', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('availabilities', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::dropIfExists('locations');
    }
};

