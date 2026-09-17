<?php

use App\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §3/§12.2.
 *
 * `invoices.status` é `varchar` + CHECK nomeado `invoices_status_check` (confirmado em
 * `pg_constraint`), não enum nativo do Postgres — a migration é DROP/ADD CONSTRAINT, não
 * `ALTER TYPE`. `draft` é necessário para o fluxo de faturamento do atendimento; `refunded`
 * corrige um bug existente (`PaymentService::refundPayment()` já escrevia esse valor sem ele
 * estar na lista permitida, o que derrubava todo estorno com violação de constraint).
 *
 * Mesmo padrão de `2026_09_22_100000_align_services_category_check_with_enum.php`: valores
 * vêm do enum (`InvoiceStatus::values()`), nunca de uma lista redigitada à mão.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'invoices_status_check';

    private const LEGACY_STATUSES = ['pending', 'paid', 'overdue', 'cancelled'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->checkStatement(InvoiceStatus::values()));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Defensivo: nenhuma linha `draft`/`refunded` pode sobreviver a um rollback que
        // reduz o CHECK de volta à lista antiga, senão a própria migration quebraria.
        DB::update(
            'UPDATE invoices SET status = ? WHERE status NOT IN (?, ?, ?, ?)',
            ['cancelled', ...self::LEGACY_STATUSES]
        );

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->checkStatement(self::LEGACY_STATUSES));
    }

    /**
     * CHECK não aceita binding — cada valor é escapado por `DB::getPdo()->quote()`. Entrada
     * de usuário não existe aqui (é enum de código ou lista fixa), ainda assim nada de
     * concatenar string crua em DDL.
     *
     * @param  list<string>  $statuses
     */
    private function checkStatement(array $statuses): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $status): string => DB::getPdo()->quote($status),
            $statuses,
        ));

        return 'ALTER TABLE invoices ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (status::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
