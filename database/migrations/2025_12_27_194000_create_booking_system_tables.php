<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->tinyInteger('day_of_week'); // 0=Sunday, 1=Monday, ..., 6=Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('slot_duration')->default(30); // minutes
            $table->integer('buffer_time')->default(0); // minutes between appointments
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['professional_id', 'day_of_week', 'is_active']);
        });

        Schema::create('blocked_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'start_datetime', 'end_datetime']);
        });

        Schema::create('waitlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('service_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('pet_id')->nullable()->constrained()->onDelete('set null');
            $table->date('preferred_date');
            $table->time('preferred_time')->nullable();
            $table->enum('status', ['active', 'notified', 'booked', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        // Add booking-related fields to appointments table
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('booking_source', ['professional', 'client', 'admin'])->default('professional')->after('notes');
            $table->timestamp('confirmed_at')->nullable()->after('booking_source');
            $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
            $table->boolean('requires_confirmation')->default(true)->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'booking_source',
                'confirmed_at',
                'cancelled_at',
                'cancellation_reason',
                'requires_confirmation'
            ]);
        });

        Schema::dropIfExists('waitlists');
        Schema::dropIfExists('blocked_times');
        Schema::dropIfExists('availabilities');
    }
};

