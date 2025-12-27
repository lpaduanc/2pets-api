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
        Schema::table('users', function (Blueprint $table) {
            // Email verification
            $table->boolean('email_verified')->default(false)->after('email_verified_at');
            $table->string('email_verification_token')->nullable()->after('email_verified');
            $table->timestamp('email_verification_sent_at')->nullable()->after('email_verification_token');

            // Profile completion
            $table->boolean('profile_completed')->default(false)->after('email_verification_sent_at');

            // Personal information
            $table->string('cpf', 14)->nullable()->unique()->after('phone');
            $table->date('birth_date')->nullable()->after('cpf');

            // User type (expanded from role)
            $table->enum('user_type', [
                'tutor',
                'vet',
                'clinic',
                'laboratory',
                'petshop',
                'pet_hotel',
                'grooming',
                'training'
            ])->default('tutor')->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_verified',
                'email_verification_token',
                'email_verification_sent_at',
                'profile_completed',
                'cpf',
                'birth_date',
                'user_type'
            ]);
        });
    }
};
