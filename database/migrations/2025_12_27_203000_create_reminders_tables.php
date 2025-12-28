<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('pet_id')->constrained()->onDelete('cascade');
            $table->string('type'); // vaccination, medication, checkup, deworming
            $table->string('title');
            $table->text('description');
            $table->date('due_date');
            $table->date('reminder_date');
            $table->enum('status', ['pending', 'sent', 'snoozed', 'dismissed', 'completed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'reminder_date']);
            $table->index(['pet_id', 'type', 'status']);
        });

        Schema::create('reminder_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type'); // vaccination, medication, checkup, deworming
            $table->integer('days_before')->default(7);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_preferences');
        Schema::dropIfExists('reminders');
    }
};

