<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Consulta sem digitação" — colunas novas de `medical_records` (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §2): anamnese
 * estruturada, comportamento/rotina, contexto que muda conduta e conduta estruturada.
 *
 * Todos os 4 blocos JSON são objetos/listas de taxonomia fechada (validados pelos Form
 * Requests contra `config/clinical-parameters.php`) — mesma decisão de modelagem já aplicada
 * a `physical_exam` na migration anterior: JSON aqui é apropriado porque não há necessidade de
 * filtrar/agregar por dentro dessas colunas nesta fatia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->json('anamnesis_signs')->nullable()->after('chief_complaint_notes');
            $table->json('behavior_findings')->nullable()->after('anamnesis_signs');
            $table->boolean('recent_routine_change')->nullable()->after('behavior_findings');
            $table->string('recent_routine_change_notes')->nullable()->after('recent_routine_change');
            $table->json('context_flags')->nullable()->after('recent_routine_change_notes');
            $table->json('treatment_actions')->nullable()->after('treatment_plan');
            $table->string('diagnosis_status', 20)->nullable()->after('diagnosis');
        });

        $this->addPostgresCheckConstraint();
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn([
                'anamnesis_signs',
                'behavior_findings',
                'recent_routine_change',
                'recent_routine_change_notes',
                'context_flags',
                'treatment_actions',
                'diagnosis_status',
            ]);
        });
    }

    /**
     * `sqlite` (suíte de teste) não suporta `CHECK` adicionado via `ALTER TABLE` — mesmo guard
     * já usado em `2026_09_24_100000_add_clinical_fields_to_medical_records_table.php`.
     */
    private function addPostgresCheckConstraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE medical_records ADD CONSTRAINT medical_records_diagnosis_status_check '.
            "CHECK (diagnosis_status IS NULL OR diagnosis_status IN ('presumptive', 'definitive', 'differential', 'ruled_out'))"
        );
    }
};
