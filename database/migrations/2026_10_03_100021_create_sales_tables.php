<?php

use App\Enums\DiscountType;
use App\Enums\FiscalOperation;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/01-caixa-pdv.md — "Ponto de Venda".
 *
 * `sales` é a venda de BALCÃO, e não se confunde com `invoices` (fatura do atendimento
 * veterinário, contrato docs/atendimento-veterinario/09) nem com `orders` (e-commerce). As três
 * coexistem porque são três jornadas: a fatura nasce de um ato clínico e vence; o pedido tem
 * frete e entrega; a venda de balcão é presencial e fecha na hora, possivelmente sem nem um
 * cliente identificado.
 *
 * `sale_items.staff_id` aponta para `organization_members` (não `users`): a comissão é do
 * VÍNCULO da pessoa com aquela clínica, não da pessoa. O mesmo veterinário pode ter percentual
 * diferente em duas clínicas, e é o vínculo que carrega essa diferença (contrato com o doc 09).
 *
 * `sellable_type`/`sellable_id` polimórficos sobre `App\Contracts\Sellable` (Product | Service),
 * conforme a decisão de modelagem do doc 08.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            // Número sequencial POR DONO, legível para o cliente ("Venda 42"), diferente do id.
            $table->unsignedBigInteger('number')->nullable();

            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_register_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 10)->default(SaleKind::SALE->value);
            $table->string('fiscal_operation', 30)->default(FiscalOperation::IN_PERSON_CONSUMER->value);
            $table->string('status', 20)->default(SaleStatus::OPEN->value);

            $table->string('discount_type', 10)->default(DiscountType::NONE->value);
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);

            // Observações que SAEM IMPRESSAS no demonstrativo (doc 01) — separadas de `notes`
            // interno, que o cliente nunca vê.
            $table->text('printed_notes')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Orçamento convertido aponta para a venda gerada (doc 24).
            $table->foreignId('converted_to_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->date('valid_until')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'sold_at']);
            $table->index(['professional_id', 'status', 'sold_at']);
            $table->index(['cash_register_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index('kind');
        });

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->morphs('sellable');

            // Nome e preço COPIADOS no momento da venda. Ler do produto na hora de reimprimir
            // mostraria o preço de hoje numa venda do mês passado.
            $table->string('description');

            $table->foreignId('staff_id')->nullable()->constrained('organization_members')->nullOnDelete();

            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('commission_percent', 8, 4)->nullable();

            $table->timestamps();

            $table->index(['sale_id']);
            $table->index(['staff_id']);
        });

        Schema::create('sale_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained();
            $table->foreignId('account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();

            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('installments')->default(1);
            $table->timestamp('received_at');

            // Taxa da adquirente, calculada na hora a partir da forma de recebimento (doc 04).
            // Gravada aqui e não derivada depois porque a taxa CADASTRADA pode mudar; a que
            // valeu nesta venda não pode.
            $table->decimal('operator_fee', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->date('expected_settlement_date')->nullable();

            $table->foreignId('received_by')->constrained('users');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['sale_id']);
            $table->index(['payment_method_id', 'received_at']);
            $table->index(['expected_settlement_date']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();

        $checks = [
            ['kind', SaleKind::values()],
            ['status', SaleStatus::values()],
            ['fiscal_operation', FiscalOperation::values()],
            ['discount_type', DiscountType::values()],
        ];

        foreach ($checks as [$column, $values]) {
            $list = implode("','", $values);
            $connection->statement(
                "ALTER TABLE sales ADD CONSTRAINT sales_{$column}_check CHECK ({$column} IN ('{$list}'))"
            );
        }

        // Número da venda: único por dono, entre os vivos.
        $connection->statement(
            'CREATE UNIQUE INDEX sales_org_number_unique ON sales (organization_id, number) WHERE deleted_at IS NULL AND organization_id IS NOT NULL AND number IS NOT NULL'
        );
        $connection->statement(
            'CREATE UNIQUE INDEX sales_professional_number_unique ON sales (professional_id, number) WHERE deleted_at IS NULL AND organization_id IS NULL AND number IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_receipts');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
