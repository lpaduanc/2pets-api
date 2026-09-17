<?php

use App\Enums\ServiceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2 —
 * pendência registrada como "sinalizada, não decidida" na entrega anterior, resolvida
 * nesta (ver `.claude/agent-memory/backend-specialist/internacao-faturamento.md`).
 *
 * Uma internação de vários dias pode ter mais de um ato clínico Grupo A (ex.: duas
 * cirurgias em dias diferentes da mesma estadia) — a cobrança de TODOS entra na mesma
 * comanda (decisão do dono do produto, §2.2), então todos pendurados no MESMO
 * `appointment_id` (`Hospitalization.appointment_id`). Sem um campo próprio, o leitor da
 * API teria que adivinhar o que cada `MedicalRecord` representa pela ordem/data — esta
 * coluna declara explicitamente qual ato ele é (`ServiceCategory` do ato, ex.: `surgery`).
 *
 * `NULL` para todo prontuário criado pelo fluxo normal (`ConsultationService::start()` →
 * `MedicalRecordEncounterResolver`): ali `appointment.type` já é essa informação de sobra,
 * porque um agendamento comum tem no máximo UM prontuário. Só
 * `HospitalizationClinicalActService::openAct()` grava este campo.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'medical_records_act_category_check';

    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('act_category', 30)->nullable()->after('appointment_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement(ServiceCategory::values()));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE medical_records DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        }

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('act_category');
        });
    }

    /**
     * @param  list<string>  $categories
     */
    private function checkStatement(array $categories): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $category): string => DB::getPdo()->quote($category),
            $categories,
        ));

        return 'ALTER TABLE medical_records ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (act_category IS NULL OR act_category::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
