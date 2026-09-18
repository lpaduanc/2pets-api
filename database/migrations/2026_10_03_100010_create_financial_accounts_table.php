<?php

use App\Enums\FinancialAccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/04-contas-bancarias-conciliacao-cartoes.md — "Contas e cartões".
 *
 * Cada operadora de cartão é uma CONTA (`type = card_acquirer`), não uma forma de pagamento:
 * é assim que o dinheiro em trânsito entre a venda e o depósito fica visível. Ver
 * `App\Enums\FinancialAccountType`.
 *
 * `opening_balance` + `opening_balance_date` em vez de uma coluna `balance` mutável: saldo é
 * DERIVADO (`AccountBalanceService`), nunca armazenado. Saldo armazenado desanda no primeiro
 * movimento que falhar no meio de uma transação, e nunca mais bate com o extrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->string('type', 20)->default(FinancialAccountType::CHECKING->value);

            $table->string('bank_code', 10)->nullable();
            $table->string('branch', 20)->nullable();
            $table->string('branch_digit', 2)->nullable();
            $table->string('account_number', 30)->nullable();
            $table->string('account_digit', 2)->nullable();

            // `Permitir lançamentos rápidos` do SimplesVet: a conta aparece como atalho no
            // recebimento do balcão. Conta de investimento, por exemplo, não deve aparecer.
            $table->boolean('allow_quick_entry')->default(true);

            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
            $table->index('type');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE financial_accounts ADD CONSTRAINT financial_accounts_type_check CHECK (type IN ('%s'))",
            implode("','", FinancialAccountType::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
