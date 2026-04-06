<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pet_vet_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('veterinarian_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 20)->default('read');
            $table->dateTime('granted_at');
            $table->dateTime('revoked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pet_id', 'veterinarian_id', 'is_active'], 'pet_vet_access_unique');
            $table->index('veterinarian_id');
            $table->index('granted_by');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_vet_accesses');
    }
};
