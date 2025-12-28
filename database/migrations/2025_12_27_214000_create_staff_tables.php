<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade'); // Owner/employer
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Staff member account
            $table->enum('role', ['veterinarian', 'assistant', 'receptionist', 'groomer', 'technician']);
            $table->string('employee_id')->unique()->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contractor'])->default('full_time');
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('monthly_salary', 10, 2)->nullable();
            $table->json('permissions')->nullable(); // Can book appointments, can edit medical records, etc.
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained();
            $table->enum('day_of_week', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
            $table->time('start_time');
            $table->time('end_time');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['staff_id', 'day_of_week']);
            $table->index(['location_id', 'day_of_week']);
        });

        Schema::create('staff_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['vacation', 'sick_leave', 'personal', 'unpaid']);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['staff_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        // Add assigned_staff_id to appointments
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('assigned_staff_id')->nullable()->after('professional_id')->constrained('staff');
            $table->index('assigned_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['assigned_staff_id']);
            $table->dropColumn('assigned_staff_id');
        });

        Schema::dropIfExists('staff_time_off');
        Schema::dropIfExists('staff_schedules');
        Schema::dropIfExists('staff');
    }
};

