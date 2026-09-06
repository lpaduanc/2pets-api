<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onda 1 — Tarefa 1.5
 * Adds `deleted_at` (SoftDeletes) to the six core tables on the tutor's path:
 * users, pets, appointments, favorites, reviews, documents.
 *
 * Each ALTER is guarded by Schema::hasColumn for idempotency — safe to re-run on
 * environments that may have been patched out-of-band.
 *
 * Pairs with `2026_04_23_000003_add_soft_deletes_to_health_records.php`, which
 * already covered clinical sub-resources (vaccinations, exams, surgeries, etc).
 *
 * Note on `users`: the LGPD flow (LgpdController::deleteAccount) ANONYMIZES the
 * user in-place. SoftDeletes coexists with that — anonymized users may also be
 * soft-deleted to hide them from default queries while preserving the row for
 * audit trails. Restoring an anonymized user would NOT restore their data.
 */
return new class extends Migration
{
    private const TABLES = [
        'users',
        'pets',
        'appointments',
        'favorites',
        'reviews',
        'documents',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }
};
