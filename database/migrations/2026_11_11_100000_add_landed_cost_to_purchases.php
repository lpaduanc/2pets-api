<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custo de aquisição real na compra — docs/gap-petflow/PLANO.md, 1.2.
 *
 * O custo que entra no custo médio passa a ser tudo o que se pagou para ter o produto na
 * prateleira: valor da linha − desconto + frete + seguro + outras despesas + IPI + ICMS-ST.
 *
 * - `purchases`: os totais de cabeçalho que a NF-e já traz (vSeg, vOutro, vIPI, vST) e que a
 *   compra não guardava. `total_freight`/`total_discount` já existiam.
 * - `purchase_items`: a parte de cada despesa no item (rateada pelo maior resto quando a nota só
 *   traz o total) e o custo real congelado (snapshot) — `landed_unit_cost` é por unidade de
 *   ESTOQUE, ou seja, já dividido pelo fator de conversão (caixa → unidade).
 * - `supplier_products`: o fator de conversão aprendido por código do fornecedor, para a próxima
 *   nota do mesmo item já entrar em unidade.
 *
 * Linhas antigas: o custo real era o próprio `total_cost` (frete nunca entrou no custo no MVP),
 * então o backfill copia esse valor — nenhum custo histórico muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->decimal('total_insurance', 14, 2)->default(0)->after('total_freight');
            $table->decimal('total_other', 14, 2)->default(0)->after('total_insurance');
            $table->decimal('total_ipi', 14, 2)->default(0)->after('total_other');
            $table->decimal('total_icms_st', 14, 2)->default(0)->after('total_ipi');
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->decimal('freight_share', 14, 2)->default(0)->after('discount');
            $table->decimal('insurance_share', 14, 2)->default(0)->after('freight_share');
            $table->decimal('other_share', 14, 2)->default(0)->after('insurance_share');
            $table->decimal('ipi_amount', 14, 2)->default(0)->after('other_share');
            $table->decimal('icms_st_amount', 14, 2)->default(0)->after('ipi_amount');
            $table->decimal('conversion_factor', 12, 3)->default(1)->after('unit');
            $table->string('purchase_unit', 10)->nullable()->after('conversion_factor');
            $table->decimal('landed_total_cost', 14, 2)->default(0)->after('total_cost');
            $table->decimal('landed_unit_cost', 14, 4)->default(0)->after('landed_total_cost');
        });

        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->decimal('conversion_factor', 12, 3)->default(1)->after('supplier_product_code');
            $table->string('purchase_unit', 10)->nullable()->after('conversion_factor');
        });

        // SQL portável (pgsql e sqlite): fator 1 em toda linha antiga, então custo por unidade de
        // estoque = total da linha ÷ quantidade, 4 casas — o mesmo que `effectiveUnitCost` dava.
        DB::table('purchase_items')->update([
            'landed_total_cost' => DB::raw('total_cost'),
            'landed_unit_cost' => DB::raw('CASE WHEN quantity > 0 THEN ROUND(total_cost / quantity, 4) ELSE 0 END'),
        ]);
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropColumn(['conversion_factor', 'purchase_unit']);
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropColumn([
                'freight_share', 'insurance_share', 'other_share', 'ipi_amount', 'icms_st_amount',
                'conversion_factor', 'purchase_unit', 'landed_total_cost', 'landed_unit_cost',
            ]);
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn(['total_insurance', 'total_other', 'total_ipi', 'total_icms_st']);
        });
    }
};
