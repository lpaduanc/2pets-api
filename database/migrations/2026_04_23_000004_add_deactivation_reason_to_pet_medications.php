<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a medication is deactivated (vet says "paciente não precisa mais deste
 * remédio"), we want to preserve WHY. A generic `notes` field is where historical
 * context goes; a dedicated `deactivation_reason` keeps the UI unambiguous:
 * "motivo da suspensão" goes here, "observações clínicas" stay in notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_medications', function (Blueprint $table) {
            if (! Schema::hasColumn('pet_medications', 'deactivation_reason')) {
                $table->text('deactivation_reason')->nullable()->after('active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pet_medications', function (Blueprint $table) {
            if (Schema::hasColumn('pet_medications', 'deactivation_reason')) {
                $table->dropColumn('deactivation_reason');
            }
        });
    }
};
