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
        Schema::create('email_verification_throttles', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->integer('attempts')->default(1);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('reset_at')->nullable(); // When counter resets (1 hour from first attempt)
            $table->timestamps();

            // Index for quick email lookups
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_verification_throttles');
    }
};
