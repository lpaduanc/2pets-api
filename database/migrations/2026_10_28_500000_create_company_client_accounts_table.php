<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo cacheado por par (organização|profissional, cliente) — contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * `current_balance` é um único número com sinal (positivo = a clínica deve ao cliente,
 * negativo = fiado) e é reconciliável a qualquer momento por
 * `SUM(credit) - SUM(debit)` de `client_account_entries` — grava-se em cache aqui só para
 * não somar o extrato inteiro a cada leitura de saldo, mesma decisão de
 * `cash_register_movements`/`CashRegisterService`.
 *
 * O saldo é POR CLÍNICA, nunca por usuário global: o mesmo tutor atendido em duas clínicas
 * tem dois saldos independentes (marketplace multi-clínica) — ver
 * `MultiCompanyBalanceIsolationTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_client_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->boolean('allow_credit_sale')->default(false);
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Um registro por cliente dentro de CADA escopo (organização OU profissional
            // autônomo) — exigência literal da spec. Postgres trata NULL como distinto em
            // UNIQUE, então as duas constraints juntas cobrem os dois lados sem conflito.
            $table->unique(['organization_id', 'client_id']);
            $table->unique(['professional_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_client_accounts');
    }
};
