<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `vaccinations` and `surgeries` were created with `professional_id` NOT NULL and
 * `onDelete('cascade')` — leftovers from the original schema, before the append-only
 * accountability model existed. Every other clinical sub-resource (pet_dewormings,
 * pet_medications, exams) already treats the professional as optional: a tutor can
 * register a vaccine or surgery without any vet involved, and CLAUDE.md documents the
 * vet link as "se cadastrado na plataforma, vincular" — never mandatory.
 *
 * This left a bug: POST /pets/{pet}/health/vaccinations and .../surgeries by a tutor
 * always hit a 23502 not-null violation, because the controller never fills
 * professional_id for a tutor-authored record (nor should it — that would fabricate
 * clinical data attributing the act to a professional who wasn't involved).
 *
 * `nullOnDelete()` replaces `cascadeOnDelete()` for the same reason exams/dewormings/
 * medications use it: a professional leaving the platform must not delete the pet's
 * clinical history (append-only rule documented in PetHealthRecordsController).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropForeign(['professional_id']);
        });
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->foreignId('professional_id')->nullable()->change();
        });
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->foreign('professional_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropForeign(['professional_id']);
        });
        Schema::table('surgeries', function (Blueprint $table) {
            $table->foreignId('professional_id')->nullable()->change();
        });
        Schema::table('surgeries', function (Blueprint $table) {
            $table->foreign('professional_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('vaccinations', function (Blueprint $table) {
            $table->dropForeign(['professional_id']);
        });
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->foreignId('professional_id')->nullable(false)->change();
        });
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->foreign('professional_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropForeign(['professional_id']);
        });
        Schema::table('surgeries', function (Blueprint $table) {
            $table->foreignId('professional_id')->nullable(false)->change();
        });
        Schema::table('surgeries', function (Blueprint $table) {
            $table->foreign('professional_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
