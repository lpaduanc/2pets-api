<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `appointments.appointment_type_id` (achado do frontend, item 14) — vínculo OPCIONAL ao
 * cadastro configurável de "tipo de atendimento" (`appointment_types`, item 14/23: nome, cor,
 * duração default por dono). NÃO substitui `appointments.type` (`ServiceCategory`, ver
 * `2026_09_29_100000_merge_appointments_type_into_service_category.php` e
 * `docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md`) — `type` continua
 * decidindo prontuário/exame/faturamento (`MedicalRecordEncounterResolver`); esta coluna é só
 * metadado de agenda (a cor que a grade do item 21 pinta), nula em todo agendamento que não
 * escolheu um tipo configurado. `nullOnDelete()`: apagar o cadastro do tipo não pode apagar o
 * agendamento histórico, só desvincular a cor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('appointment_type_id')
                ->nullable()
                ->after('type')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('appointment_type_id');
        });
    }
};
