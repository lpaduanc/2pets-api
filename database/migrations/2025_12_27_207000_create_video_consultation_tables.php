<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->onDelete('cascade');
            $table->string('room_id')->unique();
            $table->string('provider')->default('daily'); // daily, twilio, jitsi
            $table->enum('status', ['scheduled', 'waiting', 'in_progress', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->json('participants')->nullable();
            $table->boolean('recording_enabled')->default(false);
            $table->string('recording_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'status']);
        });

        Schema::create('consultation_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_consultation_id')->constrained()->onDelete('cascade');
            $table->string('recording_id');
            $table->string('recording_url');
            $table->integer('duration_seconds');
            $table->enum('status', ['processing', 'ready', 'failed'])->default('processing');
            $table->boolean('consent_given')->default(false);
            $table->timestamps();

            $table->index('video_consultation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_recordings');
        Schema::dropIfExists('video_consultations');
    }
};

