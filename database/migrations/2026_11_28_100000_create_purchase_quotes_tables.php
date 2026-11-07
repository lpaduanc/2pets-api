<?php

use App\Enums\PurchaseQuoteStatus;
use App\Enums\PurchaseQuoteSupplierStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotação com fornecedores — docs/gap-petflow/PLANO.md, 3.5.
 *
 *  - `purchase_quotes`: a cotação (número sequencial por dono, itens, prazo padrão de resposta);
 *  - `purchase_quote_suppliers`: um link público por fornecedor. O banco guarda o SHA-256 do
 *    token (busca indexada, como `QuotePublicTokenService`) E uma cópia cifrada (`APP_KEY`) para
 *    a clínica copiar o link de novo a qualquer momento (fornecedor sem e-mail);
 *  - `purchase_quote_responses` + `purchase_quote_response_items`: a proposta (frete, prazo de
 *    entrega, condição, validade) e o preço unitário de cada item cotado. Item sem linha = não
 *    cotado por aquele fornecedor.
 *
 * Também alarga `purchase_order_items.unit_cost` para 4 casas: o pedido gerado da cotação leva o
 * preço cotado (até 4 casas, como todo custo unitário) e o pedido gerado da projeção leva o
 * `last_cost` (4 casas). Com 2 casas o banco arredondava o unitário e o total da linha (calculado
 * antes, com 4) deixava de bater com quantidade × unitário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('status', 20)->default(PurchaseQuoteStatus::DRAFT->value);
            $table->date('valid_until');
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['professional_id', 'status']);
        });

        Schema::create('purchase_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->timestamps();

            $table->unique(['purchase_quote_id', 'product_id']);
        });

        Schema::create('purchase_quote_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->text('token_encrypted')->nullable();
            $table->string('status', 20)->default(PurchaseQuoteSupplierStatus::REQUESTED->value);
            $table->date('valid_until');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['purchase_quote_id', 'supplier_id']);
        });

        Schema::create('purchase_quote_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_quote_supplier_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('freight', 14, 2)->default(0);
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->string('payment_terms')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_quote_response_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_quote_response_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_quote_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_price', 14, 4);
            $table->timestamps();

            $table->unique(['purchase_quote_response_id', 'purchase_quote_item_id']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 14, 4)->default(0)->change();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $connection->statement(sprintf("ALTER TABLE purchase_quotes ADD CONSTRAINT purchase_quotes_status_check CHECK (status IN ('%s'))", implode("','", PurchaseQuoteStatus::values())));
        // `expired` é só efetivo (calculado na leitura), nunca gravado.
        $stored = array_values(array_diff(PurchaseQuoteSupplierStatus::values(), [PurchaseQuoteSupplierStatus::EXPIRED->value]));
        $connection->statement(sprintf("ALTER TABLE purchase_quote_suppliers ADD CONSTRAINT purchase_quote_suppliers_status_check CHECK (status IN ('%s'))", implode("','", $stored)));
        $connection->statement('ALTER TABLE purchase_quote_items ADD CONSTRAINT purchase_quote_items_quantity_check CHECK (quantity > 0)');
        $connection->statement('ALTER TABLE purchase_quote_response_items ADD CONSTRAINT purchase_quote_response_items_price_check CHECK (unit_price > 0)');
        $connection->statement('ALTER TABLE purchase_quote_responses ADD CONSTRAINT purchase_quote_responses_freight_check CHECK (freight >= 0)');
        $connection->statement('CREATE UNIQUE INDEX purchase_quotes_org_number_unique ON purchase_quotes (organization_id, number) WHERE deleted_at IS NULL AND organization_id IS NOT NULL');
        $connection->statement('CREATE UNIQUE INDEX purchase_quotes_professional_number_unique ON purchase_quotes (professional_id, number) WHERE deleted_at IS NULL AND organization_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 14, 2)->default(0)->change();
        });
        Schema::dropIfExists('purchase_quote_response_items');
        Schema::dropIfExists('purchase_quote_responses');
        Schema::dropIfExists('purchase_quote_suppliers');
        Schema::dropIfExists('purchase_quote_items');
        Schema::dropIfExists('purchase_quotes');
    }
};
