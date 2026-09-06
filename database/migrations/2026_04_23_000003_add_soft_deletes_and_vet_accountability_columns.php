<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accountability + archival support for health records:
 *   1. Adds `deleted_at` to all clinical tables so tutor "delete" becomes soft-delete
 *      and vet cannot hard-delete anything (append-only history).
 *   2. Adds `deactivation_reason` to pet_medications so "end treatment" carries its
 *      motive into the audit log without needing a separate table.
 *   3. Back-fills the original `exams` table which was shipped empty (only id + timestamps)
 *      but whose model already references pet_id/exam_type/etc. — this gets the INSERTs
 *      working again.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Finish the exams table (was created empty in 2025_11_22_234417).
        Schema::table('exams', function (Blueprint $table) {
            if (! Schema::hasColumn('exams', 'pet_id')) {
                $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('exams', 'professional_id')) {
                $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('exams', 'appointment_id')) {
                $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            }
            if (! Schema::hasColumn('exams', 'exam_type')) {
                $table->string('exam_type', 120);
            }
            if (! Schema::hasColumn('exams', 'exam_name')) {
                $table->string('exam_name', 120);
            }
            if (! Schema::hasColumn('exams', 'exam_date')) {
                $table->date('exam_date');
            }
            if (! Schema::hasColumn('exams', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('exams', 'status')) {
                $table->string('status', 20)->default('requested');
            }
        });

        // Soft deletes on every clinical sub-resource.
        foreach (['vaccinations', 'pet_dewormings', 'pet_medications', 'surgeries', 'exams', 'hospitalizations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        // Deactivation context for continuous treatments.
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

        foreach (['hospitalizations', 'exams', 'surgeries', 'pet_medications', 'pet_dewormings', 'vaccinations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }

        // We don't try to undo the exams back-fill — that's rescuing a broken state,
        // not something to roll back.
    }
};
