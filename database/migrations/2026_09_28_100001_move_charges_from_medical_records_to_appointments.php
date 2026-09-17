<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.3.
 *
 * A conta muda de dono: do PRONTUÁRIO para o AGENDAMENTO. Banho e tosa não gera
 * prontuário (não existe módulo de grooming — é só `appointments.type` + categoria de
 * `ServiceCategory`), então pendurar a conta em `medical_records` deixava banho e
 * tosa sem como lançar cobrança incremental. O agendamento é o que TODO atendimento
 * tem, clínico ou não — pendurar ali elimina o caso especial em vez de duplicar
 * mecanismo.
 *
 * As linhas existentes são artefato de verificação manual da sessão anterior (docs
 * §13.3: "os dados existentes... podem ser migrados pelo appointment_id do prontuário,
 * ou descartar, tanto faz"). Migradas quando o prontuário tem agendamento (sempre tem,
 * no fluxo real — `ConsultationService` cria os dois juntos); descartadas quando não.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('medical_record_charges', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('medical_record_id')
                ->constrained()->cascadeOnDelete();
        });

        DB::statement('
            UPDATE medical_record_charges
            SET appointment_id = medical_records.appointment_id
            FROM medical_records
            WHERE medical_records.id = medical_record_charges.medical_record_id
        ');

        DB::table('medical_record_charges')->whereNull('appointment_id')->delete();

        Schema::table('medical_record_charges', function (Blueprint $table) {
            $table->dropForeign(['medical_record_id']);
            $table->dropColumn('medical_record_id');
        });

        DB::statement('ALTER TABLE medical_record_charges ALTER COLUMN appointment_id SET NOT NULL');

        Schema::rename('medical_record_charges', 'appointment_charges');

        // A migration original tinha `$table->index('medical_record_id')` — o índice some
        // junto com a coluna. `appointment_id` é filtrado o tempo todo (`Appointment::
        // charges()`, `AppointmentInvoiceService`), precisa do próprio índice.
        Schema::table('appointment_charges', function (Blueprint $table) {
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::rename('appointment_charges', 'medical_record_charges');

        Schema::table('medical_record_charges', function (Blueprint $table) {
            $table->dropForeign(['appointment_id']);
            $table->dropColumn('appointment_id');
            $table->foreignId('medical_record_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }
};
