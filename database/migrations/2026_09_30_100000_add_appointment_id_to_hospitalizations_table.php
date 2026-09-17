<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §1.
 *
 * A internação passa a pendurar num `Appointment` (`type = hospitalization`) — o mesmo
 * objeto que já atravessa `AppointmentInvoiceService`/`AppointmentChargeService`/
 * `AppointmentStatus`. `NOT NULL` desde o início: zero linhas em `hospitalizations` nesta
 * sessão (confirmado antes de escrever esta migration), então não há backfill a fazer.
 * `restrictOnDelete()`: uma internação nunca pode ficar órfã de agendamento por um DELETE
 * em cascata acidental — o agendamento de uma internação só é encerrado (`completed`),
 * nunca apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitalizations', function (Blueprint $table) {
            $table->foreignId('appointment_id')
                ->after('professional_id')
                ->constrained()
                ->restrictOnDelete();

            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::table('hospitalizations', function (Blueprint $table) {
            $table->dropForeign(['appointment_id']);
            $table->dropColumn('appointment_id');
        });
    }
};
