<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - `sale_receipts.reversed_at`: o recebimento já foi estornado (cancelamento da venda hoje, o
 *   "Estorno de um recebimento" do PLANO 2.3 depois). É a chave de idempotência da unidade de
 *   estorno: estornar de novo não devolve o dinheiro duas vezes.
 * - `client_account_entries.type` ganha `return_credit`: devolução de venda em crédito na conta
 *   corrente do cliente; e `sale_debit_reversal`: estorno do débito de venda a prazo cancelada.
 *   Lista de tipos CONGELADA aqui (migration não lê enum, que muda depois).
 */
return new class extends Migration
{
    private const TYPES_BEFORE = [
        'sale_debit', 'payment_credit', 'advance_credit', 'refund_debit',
        'adjustment_debit', 'adjustment_credit', 'package_credit', 'loyalty_credit',
    ];

    public function up(): void
    {
        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('received_at');
        });

        $this->replaceTypeCheck([...self::TYPES_BEFORE, 'return_credit', 'sale_debit_reversal']);
    }

    public function down(): void
    {
        $this->replaceTypeCheck(self::TYPES_BEFORE);

        Schema::table('sale_receipts', function (Blueprint $table): void {
            $table->dropColumn('reversed_at');
        });
    }

    /** @param  list<string>  $types */
    private function replaceTypeCheck(array $types): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE client_account_entries DROP CONSTRAINT IF EXISTS client_account_entries_type_check');
        DB::statement(sprintf(
            "ALTER TABLE client_account_entries ADD CONSTRAINT client_account_entries_type_check CHECK (type IN ('%s'))",
            implode("','", $types)
        ));
    }
};
