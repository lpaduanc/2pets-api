<?php

use App\Enums\CommissionSettlementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md — fechamento
 * de comissão por funcionário.
 *
 * `financial_entry_id` SEM FK de propósito: `financial_entries` (item 02) já existe no código
 * na data desta migration (`App\Services\Finance\FinancialEntryService`), mas o lançamento
 * automático da despesa de comissão no fechamento não entrou nesta rodada — ver a decisão
 * registrada em `docs/gap-simplesvet/contratos/09-contrato-api.md`. A liquidação fica manual
 * (`paid_at`/`payment_method`/`payment_reference`), exatamente como a spec previu como
 * alternativa caso a integração automática não coubesse nesta tarefa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('organization_members');

            $table->date('period_from');
            $table->date('period_to');
            $table->date('received_until');
            $table->decimal('total_amount', 14, 2)->default(0);

            $table->string('status', 20)->default(CommissionSettlementStatus::OPEN->value);

            // Sem FK: `financial_entries` já existe, mas a integração automática ficou fora
            // desta rodada (ver o comentário da migration). Campo pronto para a FK + backfill
            // no dia em que a integração entrar.
            $table->unsignedBigInteger('financial_entry_id')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'staff_id', 'status']);
            $table->index(['professional_id', 'staff_id', 'status']);
        });

        Schema::create('commission_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();

            // Nulo quando não havia `commission_rule` cadastrada e o item caiu no fallback
            // do próprio `sale_items.commission_percent` (congelado na venda, doc 08).
            $table->foreignId('commission_rule_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('base_amount', 14, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->timestamps();

            // Um item de venda só pode compor UM fechamento, nunca dois — critério de aceite.
            $table->unique('sale_item_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE commission_settlements ADD CONSTRAINT commission_settlements_status_check CHECK (status IN ('%s'))",
            implode("','", CommissionSettlementStatus::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settlement_items');
        Schema::dropIfExists('commission_settlements');
    }
};
