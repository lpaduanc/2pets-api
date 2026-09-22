<?php

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md — o livro-razão CONTÁBIL
 * (categoria, competência, DRE). Não substitui `cash_register_movements` (razão OPERACIONAL da
 * gaveta física) nem `FinancialOverviewService` (leitura de `invoices`/`sales` para a tela de
 * Financeiro do PDV) — os três convivem, cada um com sua origem. Ver cabeçalho de
 * `AccountBalanceService` e `FinancialOverviewService` para o mapa completo.
 *
 * `accrual_date` (competência) e `paid_at` (caixa) na MESMA linha, nunca duas linhas: o
 * relatório escolhe qual data agrupa por mês sem duplicar dado.
 *
 * `net_amount` = `amount - discount + fine + interest`, gravado (não derivado na leitura) para
 * o relatório não recalcular a cada consulta.
 *
 * `reference_type`/`reference_id` é o polimórfico manual (não `morphs()`) porque as origens são
 * heterogêneas por natureza: `sale`, `invoice`, `purchase_installment`, `manual` — documentado
 * aqui porque o item 09 (comissionamento) vai adicionar `commission_settlement` como quinto
 * valor, sem migração nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->foreignId('financial_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->string('nature', 10);

            $table->date('due_date');
            $table->date('accrual_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('fine', 14, 2)->default(0);
            $table->decimal('interest', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);

            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 14, 2)->nullable();
            $table->string('status', 20)->default(FinancialEntryStatus::OPEN->value);

            $table->uuid('series_id')->nullable();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installment_total')->nullable();

            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'nature', 'status']);
            $table->index(['professional_id', 'nature', 'status']);
            $table->index('due_date');
            $table->index('accrual_date');
            $table->index('series_id');
            $table->index(['reference_type', 'reference_id']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $checks = [
            ['nature', FinancialNature::values()],
            ['status', FinancialEntryStatus::values()],
        ];

        foreach ($checks as [$column, $values]) {
            $connection->statement(sprintf(
                "ALTER TABLE financial_entries ADD CONSTRAINT financial_entries_%s_check CHECK (%s IN ('%s'))",
                $column, $column, implode("','", $values)
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_entries');
    }
};
