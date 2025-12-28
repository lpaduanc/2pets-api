<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lost_pet_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['active', 'found', 'cancelled'])->default('active');
            $table->text('description');
            $table->string('last_seen_location');
            $table->decimal('last_seen_latitude', 10, 7)->nullable();
            $table->decimal('last_seen_longitude', 10, 7)->nullable();
            $table->decimal('alert_radius_km', 5, 2)->default(5.00); // Alert radius
            $table->datetime('last_seen_at');
            $table->json('contact_info'); // Emergency contact details
            $table->json('photos')->nullable(); // Additional photos
            $table->string('microchip_number')->nullable();
            $table->decimal('reward_amount', 10, 2)->nullable();
            $table->datetime('found_at')->nullable();
            $table->text('found_details')->nullable();
            $table->integer('views_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->timestamps();

            $table->index(['pet_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['last_seen_latitude', 'last_seen_longitude']);
        });

        Schema::create('found_pet_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lost_pet_alert_id')->constrained()->onDelete('cascade');
            $table->foreignId('reporter_user_id')->nullable()->constrained('users');
            $table->string('reporter_name');
            $table->string('reporter_phone');
            $table->string('reporter_email')->nullable();
            $table->text('description');
            $table->string('location');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->datetime('spotted_at');
            $table->json('photos')->nullable();
            $table->enum('status', ['pending', 'verified', 'false_positive', 'duplicate'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['lost_pet_alert_id', 'status']);
            $table->index('reporter_user_id');
        });

        Schema::create('lost_pet_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lost_pet_alert_id')->constrained()->onDelete('cascade');
            $table->foreignId('notified_user_id')->constrained('users');
            $table->enum('channel', ['email', 'push', 'sms'])->default('push');
            $table->datetime('sent_at');
            $table->datetime('opened_at')->nullable();
            $table->timestamps();

            $table->index(['lost_pet_alert_id', 'notified_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_pet_notifications');
        Schema::dropIfExists('found_pet_reports');
        Schema::dropIfExists('lost_pet_alerts');
    }
};

