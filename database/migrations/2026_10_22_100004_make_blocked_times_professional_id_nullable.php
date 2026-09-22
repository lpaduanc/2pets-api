<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 21 do backlog gap-simplesvet — o bug de "bloqueio amplo" não era só de query: o schema
 * NUNCA permitiu `professional_id = null` de verdade. `blocked_times` nasceu com
 * `professional_id` NOT NULL (`2025_12_27_194000_create_booking_system_tables.php:27`); a
 * migration que adicionou `organization_id`/`location_id`
 * (`2026_09_14_100000_add_organization_id_to_commercial_tables.php`) só criou as colunas
 * novas, sem relaxar essa constraint. Um bloqueio "da empresa inteira" nunca pôde ser
 * inserido no banco — a leitura (`AvailabilityService`/`AvailableDaysCalculator`) só é
 * metade da correção.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->dropForeign(['professional_id']);
        });
        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->foreignId('professional_id')->nullable()->change();
        });
        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->foreign('professional_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->dropForeign(['professional_id']);
        });
        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->foreignId('professional_id')->nullable(false)->change();
        });
        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->foreign('professional_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
