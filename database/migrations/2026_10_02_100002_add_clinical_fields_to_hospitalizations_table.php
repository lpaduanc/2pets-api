<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha padronizada de internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §3/§5/§7.
 *
 * `indicating_medical_record_id`: vínculo que faltava entre a internação e a
 * consulta/emergência (Grupo A, em OUTRO agendamento) que indicou internar — nullable
 * porque a internação pode nascer de um walk-in de emergência sem prontuário prévio
 * registrado. `nullOnDelete()`: o prontuário que indicou pode, em tese, ser removido sem
 * levar a internação junto — o vínculo só deixa de aparecer no topo da ficha.
 *
 * `estimated_discharge_date`: previsão de alta, editável a qualquer momento durante
 * `active`, puramente informativa.
 *
 * `discharge_summary`: resumo de alta — nullable na COLUNA (a obrigatoriedade ao fechar a
 * estadia é regra de aplicação em `UpdateHospitalizationRequest`, mesmo padrão já usado
 * noutros campos condicionais do domínio, não CHECK de banco).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitalizations', function (Blueprint $table) {
            $table->foreignId('indicating_medical_record_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained('medical_records')
                ->nullOnDelete();

            $table->date('estimated_discharge_date')->nullable()->after('discharge_date');
            $table->text('discharge_summary')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('hospitalizations', function (Blueprint $table) {
            $table->dropForeign(['indicating_medical_record_id']);
            $table->dropColumn(['indicating_medical_record_id', 'estimated_discharge_date', 'discharge_summary']);
        });
    }
};
