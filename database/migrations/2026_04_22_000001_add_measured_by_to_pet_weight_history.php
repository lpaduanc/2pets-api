<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track who registered each weight entry — needed so tutors can see when a vet
 * added a data point vs. when they added it themselves, and so the audit trail
 * remains complete after a PetVetAccess grant is revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_weight_history', function (Blueprint $table) {
            $table->foreignId('measured_by_user_id')
                ->nullable()
                ->after('pet_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pet_weight_history', function (Blueprint $table) {
            $table->dropForeign(['measured_by_user_id']);
            $table->dropColumn('measured_by_user_id');
        });
    }
};
