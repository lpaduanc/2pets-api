<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 21 do backlog gap-simplesvet — "fila do dia". Em vez de um `queue_status` paralelo
 * ao `AppointmentStatus` já existente, o rótulo de fila é derivado do par
 * (`status`, `checked_in_at`): aguardando = confirmed/scheduled + checked_in_at preenchido;
 * em atendimento = in_progress; atendido = completed. Evita duas máquinas de estado
 * concorrentes (ver spec 21 §Escopo item 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->timestamp('checked_in_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('checked_in_at');
        });
    }
};
