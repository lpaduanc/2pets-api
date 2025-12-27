<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, we need to use raw SQL to modify an ENUM column
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM(
            'tutor',
            'vet',
            'clinic',
            'laboratory',
            'petshop',
            'pet_hotel',
            'grooming',
            'training',
            'company'
        ) DEFAULT 'tutor'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to original enum without 'company'
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM(
            'tutor',
            'vet',
            'clinic',
            'laboratory',
            'petshop',
            'pet_hotel',
            'grooming',
            'training'
        ) DEFAULT 'tutor'");
    }
};
