<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devolução de compra ao fornecedor (parcial) — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.6. Até aqui só existia o cancelamento da compra inteira
 * (`PurchaseService::cancel`). Este registro mexe só no ESTOQUE (saída `return_out`); o efeito
 * financeiro (crédito com o fornecedor, abatimento de parcela) é decisão pendente do dono.
 *
 * Os valores da linha são congelados aqui (rateio acumulado sobre o item da compra): a NF-e de
 * devolução lê estes números, nunca recalcula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index('purchase_id');
        });

        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->decimal('products_total', 14, 2);
            $table->decimal('discount_share', 14, 2)->default(0);
            $table->decimal('ipi_share', 14, 2)->default(0);
            $table->decimal('icms_st_share', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('stock_value', 14, 2)->default(0);
            $table->timestamps();

            $table->index('purchase_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
