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
        // Modify the role enum to include 'admin'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('tutor', 'professional', 'company', 'admin') NOT NULL DEFAULT 'tutor'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Check if any admin users exist
        $hasAdmins = DB::table('users')->where('role', 'admin')->exists();

        if ($hasAdmins) {
            // Change admin users to tutor before removing the enum value
            DB::table('users')->where('role', 'admin')->update(['role' => 'tutor']);
        }

        // Revert the enum to original values
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('tutor', 'professional', 'company') NOT NULL DEFAULT 'tutor'");
    }
};
