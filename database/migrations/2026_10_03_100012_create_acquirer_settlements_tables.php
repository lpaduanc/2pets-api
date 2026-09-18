<?php

use App\Enums\AcquirerSettlementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/04 — "Conciliação de cartões".
 *
 * Um depósito da adquirente é a contrapartida de N recebimentos de venda. `acquirer_settlement_items`
 * é essa ligação N:N — sem ela, "conciliado" seria só um carimbo e ninguém saberia QUAIS vendas
 * compõem o depósito quando o valor não fecha.
 *
 * `gross_amount` − `fee_amount` = `net_amount` gravados os três: o líquido é o que cai no banco,
 * o bruto é o que o cliente pagou, e a taxa é despesa que o DRE (doc 02) precisa enxergar
 * separada. Derivar um dos três na leitura esconderia a despesa.
 *
 * Observação do documento, respeitada aqui: este é o eixo CLÍNICA × ADQUIRENTE. O eixo
 * PLATAFORMA × PROFISSIONAL (repasse do marketplace) é `payouts`/`commissions`, tabela
 * diferente, de propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquirer_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();
            $table->date('deposit_date');
            $table->string('description');
            $table->foreignId('destination_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();

            $table->decimal('gross_amount', 14, 2);
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);

            $table->string('status', 20)->default(AcquirerSettlementStatus::PENDING->value);
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('divergence_note')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status', 'deposit_date']);
            $table->index(['professional_id', 'status', 'deposit_date']);
        });

        Schema::create('acquirer_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acquirer_settlement_id')->constrained()->cascadeOnDelete();

            // FK criada depois, em `2026_10_03_100027_link_settlement_items_to_sale_receipts`:
            // `sale_receipts` só nasce no doc 01, e uma migration não pode depender de uma
            // tabela que ainda não existe. A coluna já vem indexada.
            $table->unsignedBigInteger('sale_receipt_id');

            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['acquirer_settlement_id', 'sale_receipt_id'], 'settlement_receipt_unique');
            $table->index('sale_receipt_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE acquirer_settlements ADD CONSTRAINT acquirer_settlements_status_check CHECK (status IN ('%s'))",
            implode("','", AcquirerSettlementStatus::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('acquirer_settlement_items');
        Schema::dropIfExists('acquirer_settlements');
    }
};
