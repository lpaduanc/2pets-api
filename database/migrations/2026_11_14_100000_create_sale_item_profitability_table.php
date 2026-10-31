<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rentabilidade por item de venda — docs/gap-petflow/PLANO.md, 1.5. Uma linha por `sale_items`
 * (1:1), recalculada por `SaleProfitabilityService::recompute` a cada fato que muda o resultado
 * (venda finalizada, recebimento, estorno, devolução, comissão). Valores já líquidos da parte
 * devolvida; `quantity` é a quantidade que ficou com o cliente. `version` sobe só quando algum
 * valor muda — recalcular de novo sem mudança é no-op (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_item_profitability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('gross_total', 14, 2);
            $table->decimal('allocated_header_discount', 14, 2);
            $table->decimal('net_revenue', 14, 2);
            $table->decimal('cost_amount', 14, 2);
            $table->decimal('card_fee_share', 14, 2);
            $table->decimal('tax_amount', 14, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->decimal('contribution_margin', 14, 2);
            $table->timestamp('computed_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index('sale_id');
            $table->index(['organization_id', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_profitability');
    }
};
