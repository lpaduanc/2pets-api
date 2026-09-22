<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 do fluxo de agendamento: `availabilities` e `blocked_times` ganham endpoint de
 * escrita pela primeira vez (`Api/Professional/AvailabilityController`/`BlockedTimeController`)
 * — e com escrita vem `DELETE`. Regra do projeto é soft delete em tudo (nunca `DELETE` físico);
 * as duas tabelas nasceram sem `deleted_at` porque, até aqui, só eram lidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availabilities', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('availabilities', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
