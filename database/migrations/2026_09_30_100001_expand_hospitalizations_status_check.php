<?php

use App\Enums\HospitalizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3: alta,
 * transferência e óbito fecham a estadia igualmente — mas `hospitalizations.status` nunca
 * teve valor para óbito (`active|discharged|transferred` apenas). Sem esta migration, o
 * único jeito de registrar um óbito era forçar `discharged` com nota em texto livre.
 *
 * Mesmo padrão de `2026_09_27_100004_expand_payments_method_check.php`: valores vêm do
 * enum, DROP/ADD CONSTRAINT (a coluna é `varchar` + CHECK, não enum nativo do Postgres).
 */
return new class extends Migration
{
    private const CONSTRAINT = 'hospitalizations_status_check';

    private const LEGACY_STATUSES = ['active', 'discharged', 'transferred'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE hospitalizations DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->checkStatement(HospitalizationStatus::values()));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Defensivo, mesmo padrão das migrations irmãs: nenhuma linha `deceased` pode
        // sobreviver a um rollback que reduz o CHECK de volta à lista antiga.
        DB::update("UPDATE hospitalizations SET status = 'discharged' WHERE status NOT IN (?, ?, ?)", self::LEGACY_STATUSES);

        DB::statement('ALTER TABLE hospitalizations DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->checkStatement(self::LEGACY_STATUSES));
    }

    /**
     * @param  list<string>  $statuses
     */
    private function checkStatement(array $statuses): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $status): string => DB::getPdo()->quote($status),
            $statuses,
        ));

        return 'ALTER TABLE hospitalizations ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (status::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
