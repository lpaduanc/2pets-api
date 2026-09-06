<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft deletes to every pet-scoped clinical record table.
 *
 * Why: the accountability flow requires clinical history to be immutable for vets.
 * Owners may "arquivar" (soft-delete) but a vet must never hard-delete. Implementing
 * soft deletes is the prerequisite so the `destroy` endpoint preserves history.
 *
 * Tables touched: vaccinations, pet_dewormings, pet_medications, surgeries, exams,
 * hospitalizations. Queries that don't use `withTrashed()` will now transparently
 * filter out archived rows, which is the desired behavior for both tutor and vet
 * reads (they should never see archived clinical records unless explicitly asked).
 */
return new class extends Migration
{
    private const TABLES = [
        'vaccinations',
        'pet_dewormings',
        'pet_medications',
        'surgeries',
        'exams',
        'hospitalizations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
