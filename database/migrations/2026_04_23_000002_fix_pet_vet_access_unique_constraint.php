<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the naive `UNIQUE(pet_id, veterinarian_id, is_active)` constraint with a
 * **partial** unique index that only fires when the access is actually live
 * (status IN ('pending', 'accepted')).
 *
 * Why: the old constraint blocked a vet from re-requesting access after an accidental
 * rejection or revoke — a single historical `is_active = false` row already filled
 * the slot. Business rules (see PetVetAccessController::requestAccess) already dedupe
 * new requests against pending/accepted rows, so the DB just needs to guarantee there
 * aren't two live rows for the same (pet, vet) pair. Rejected and revoked rows can
 * accumulate as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_vet_accesses', function ($table) {
            $table->dropUnique('pet_vet_access_unique');
        });

        DB::statement(
            "CREATE UNIQUE INDEX pet_vet_access_live_unique
             ON pet_vet_accesses (pet_id, veterinarian_id)
             WHERE status IN ('pending', 'accepted')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pet_vet_access_live_unique');

        Schema::table('pet_vet_accesses', function ($table) {
            $table->unique(['pet_id', 'veterinarian_id', 'is_active'], 'pet_vet_access_unique');
        });
    }
};
