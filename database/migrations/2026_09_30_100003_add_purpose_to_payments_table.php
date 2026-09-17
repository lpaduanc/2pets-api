<?php

use App\Enums\PaymentPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3-bis.2.
 *
 * Distingue "recebimento parcial registrado" (`advance`, restrito à internação) de "o
 * pagamento que fecha a fatura" (`settlement`). `DEFAULT 'settlement'`: toda linha que já
 * existe hoje é implicitamente um acerto final — nenhuma migração de dado, nenhuma mudança
 * de comportamento para fatura que nunca teve adiantamento.
 *
 * O CHECK é adicionado à parte (guardado a pgsql, mesmo padrão das migrations irmãs) porque
 * `$table->enum()` numa coluna nova de uma tabela existente exigiria recriar a coluna no
 * Postgres; DROP/ADD CONSTRAINT sobre uma `string` normal é mais simples e é o padrão já
 * usado neste projeto para toda coluna "enum simulado".
 */
return new class extends Migration
{
    private const CONSTRAINT = 'payments_purpose_check';

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('purpose', 20)->default(PaymentPurpose::SETTLEMENT->value)->after('gateway_payment_id');

            $table->index(['invoice_id', 'purpose']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement(PaymentPurpose::values()));
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['invoice_id', 'purpose']);
            $table->dropColumn('purpose');
        });
    }

    /**
     * @param  list<string>  $purposes
     */
    private function checkStatement(array $purposes): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $purpose): string => DB::getPdo()->quote($purpose),
            $purposes,
        ));

        return 'ALTER TABLE payments ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (purpose::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
