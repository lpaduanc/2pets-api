<?php

use App\Enums\ProductPurpose;
use App\Enums\PurchaseInstallmentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/06-compras-fornecedores-xml.md — pedido de compra, compra
 * (entrada de nota) e as parcelas a pagar da compra.
 *
 * `purchase_installments` existe porque o doc 03 (contas a pagar) ainda não foi implementado:
 * a compra parcelada precisa registrar suas parcelas em algum lugar, com vencimento, valor,
 * forma e conta. Quando `accounts_payable` entrar, estas linhas são a origem dela (uma
 * migração 1:1) — o que não pode acontecer é a compra "esquecer" que foi parcelada.
 *
 * Valores dos itens CONGELADOS (custo, markup, preço aplicado): a compra é documento, e o
 * custo médio do produto muda a cada entrada seguinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('code');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default(PurchaseOrderStatus::DRAFT->value);
            $table->date('expected_at')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['professional_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->integer('received_quantity')->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('code');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('invoice_number', 20)->nullable();
            $table->string('invoice_series', 5)->nullable();
            // Chave de acesso da NF-e (44 dígitos) — impede lançar a mesma nota duas vezes.
            $table->string('invoice_key', 44)->nullable();
            $table->date('invoice_issued_at')->nullable();
            $table->timestamp('entered_at');

            $table->decimal('total_products', 14, 2)->default(0);
            $table->decimal('total_freight', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Plano de pagamento do rascunho. As parcelas (`purchase_installments`) só nascem
            // no `receive()`: rascunho não é dívida.
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('installments_count')->nullable();
            $table->date('first_due_date')->nullable();
            $table->unsignedSmallInteger('installment_interval_days')->default(30);

            $table->string('status', 20)->default(PurchaseStatus::DRAFT->value);
            $table->string('xml_path')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'entered_at']);
            $table->index(['professional_id', 'status', 'entered_at']);
            $table->index('supplier_id');
        });

        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_product_code', 60)->nullable();
            $table->string('description_on_invoice')->nullable();
            $table->integer('quantity');
            $table->string('unit', 10)->nullable();
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2);
            $table->decimal('markup_percent', 8, 4)->nullable();
            $table->decimal('suggested_price', 14, 2)->nullable();
            $table->decimal('applied_sale_price', 14, 2)->nullable();
            $table->string('batch', 60)->nullable();
            $table->date('expires_at')->nullable();
            $table->string('ncm', 8)->nullable();
            $table->string('purpose', 20)->default(ProductPurpose::RESALE->value);
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::create('purchase_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default(PurchaseInstallmentStatus::PENDING->value);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $checks = [
            ['purchases', 'status', PurchaseStatus::values()],
            ['purchase_orders', 'status', PurchaseOrderStatus::values()],
            ['purchase_installments', 'status', PurchaseInstallmentStatus::values()],
            ['purchase_items', 'purpose', ProductPurpose::values()],
        ];

        foreach ($checks as [$table, $column, $values]) {
            $connection->statement(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s_%s_check CHECK (%s IN ('%s'))",
                $table, $table, $column, $column, implode("','", $values)
            ));
        }

        foreach (['purchases', 'purchase_orders'] as $table) {
            $connection->statement(
                "CREATE UNIQUE INDEX {$table}_org_code_unique ON {$table} (organization_id, code) WHERE deleted_at IS NULL AND organization_id IS NOT NULL"
            );
            $connection->statement(
                "CREATE UNIQUE INDEX {$table}_professional_code_unique ON {$table} (professional_id, code) WHERE deleted_at IS NULL AND organization_id IS NULL"
            );
        }

        // A mesma NF-e não entra duas vezes para o mesmo dono (compra cancelada libera).
        $connection->statement(
            "CREATE UNIQUE INDEX purchases_org_invoice_key_unique ON purchases (organization_id, invoice_key) WHERE deleted_at IS NULL AND invoice_key IS NOT NULL AND status <> 'cancelled' AND organization_id IS NOT NULL"
        );
        $connection->statement(
            "CREATE UNIQUE INDEX purchases_professional_invoice_key_unique ON purchases (professional_id, invoice_key) WHERE deleted_at IS NULL AND invoice_key IS NOT NULL AND status <> 'cancelled' AND organization_id IS NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_installments');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
