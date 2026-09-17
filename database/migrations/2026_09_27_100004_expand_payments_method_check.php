<?php

use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Achado desta sessão, não listado no contrato original: `App\Enums\PaymentMethod` ganhou
 * `CASH` (contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §3), mas
 * `payments.method` continuava com o CHECK antigo (`pix|credit_card|debit_card|boleto`) —
 * confirmado por `INSERT` real: `payments_method_check` rejeitava `'cash'` com 500. Sem esta
 * migration, `POST /invoices/{id}/mark-as-paid` com `method=cash` (o caso mais comum de
 * balcão de vet volante/petshop, exatamente o que o contrato pede) nunca funcionaria.
 *
 * Mesmo padrão de `2026_09_27_100002_expand_invoices_status_check.php`: valores vêm do enum.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'payments_method_check';

    private const LEGACY_METHODS = ['pix', 'credit_card', 'debit_card', 'boleto'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->checkStatement(PaymentMethod::values()));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Defensivo: nenhuma linha `cash` pode sobreviver a um rollback que reduz o CHECK de
        // volta à lista antiga, senão a própria migration quebraria.
        DB::update("UPDATE payments SET method = 'pix' WHERE method NOT IN (?, ?, ?, ?)", self::LEGACY_METHODS);

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->checkStatement(self::LEGACY_METHODS));
    }

    /**
     * @param  list<string>  $methods
     */
    private function checkStatement(array $methods): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $method): string => DB::getPdo()->quote($method),
            $methods,
        ));

        return 'ALTER TABLE payments ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (method::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
