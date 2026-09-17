<?php

use App\Enums\HospitalizationCareStatus;
use App\Enums\HospitalizationCareType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checklist de cuidados da internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4/§7.
 *
 * Log de eventos (não grade fixa por turno), append-only, mesmo padrão de
 * `hospitalization_progress_notes`. `care_type`/`status` são `varchar` + CHECK (mesmo padrão
 * de `medical_records.act_category`) — coluna nova, sem risco de leitura antiga, então o
 * model casteia direto para o Enum PHP correspondente.
 *
 * `notes` obrigatório quando `status = not_done` é regra de APLICAÇÃO
 * (`StoreHospitalizationCareLogRequest`), não CHECK — mesmo padrão já usado para
 * `hospitalizations.discharge_summary` (doc 12 §5.2).
 */
return new class extends Migration
{
    private const CARE_TYPE_CONSTRAINT = 'hospitalization_care_logs_care_type_check';

    private const STATUS_CONSTRAINT = 'hospitalization_care_logs_status_check';

    public function up(): void
    {
        Schema::create('hospitalization_care_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospitalization_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('prescription_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('care_type', 30);
            $table->string('status', 20);
            $table->timestamp('performed_at');
            $table->timestamp('created_at')->useCurrent();
            $table->text('notes')->nullable();

            $table->index(['hospitalization_id', 'performed_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('care_type', self::CARE_TYPE_CONSTRAINT, HospitalizationCareType::values()));
        DB::statement($this->checkStatement('status', self::STATUS_CONSTRAINT, HospitalizationCareStatus::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitalization_care_logs');
    }

    /**
     * @param  list<string>  $values
     */
    private function checkStatement(string $column, string $constraint, array $values): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $value): string => DB::getPdo()->quote($value),
            $values,
        ));

        return 'ALTER TABLE hospitalization_care_logs ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
