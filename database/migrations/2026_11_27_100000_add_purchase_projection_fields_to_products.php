<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projeção de compra (docs/gap-petflow/PLANO.md, 3.4): parâmetros por produto, todos opcionais —
 * vazio usa o padrão (`PurchaseProjectionService`):
 *  - `preferred_supplier_id`: fornecedor padrão (vazio = o da última compra);
 *  - `lead_time_days`: prazo de entrega (vazio = o do fornecedor; sem ele, 15 dias);
 *  - `target_coverage_days`: cobertura-alvo depois da entrega (vazio = 30 dias);
 *  - `purchase_multiple`: fator de compra (caixa com 12, saco de 0,5 kg; vazio = 1 unidade, ou a
 *    menor fração da unidade de venda no produto fracionado);
 *  - `use_safety_stock`: soma o estoque de segurança z × σ × √prazo (z = 1,65).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->unsignedSmallInteger('target_coverage_days')->nullable();
            $table->decimal('purchase_multiple', 12, 4)->nullable();
            $table->boolean('use_safety_stock')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preferred_supplier_id');
            $table->dropColumn(['lead_time_days', 'target_coverage_days', 'purchase_multiple', 'use_safety_stock']);
        });
    }
};
