<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Company-specific fields
            $table->string('cnpj')->nullable()->after('cpf');
            $table->string('employee_count')->nullable()->after('cnpj');
            $table->text('additional_notes')->nullable()->after('employee_count');
            $table->enum('registration_status', ['pending', 'approved', 'rejected', 'completed'])->default('pending')->after('profile_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cnpj', 'employee_count', 'additional_notes', 'registration_status']);
        });
    }
};
