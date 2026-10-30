<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custo UNITÁRIO com 4 casas — docs/gap-petflow/PLANO.md, seção 3 item 2 (decisão de 2026-10-30,
 * revendo a Fase 0, que tinha levado tudo para 2 casas). Insumo comprado em litro e consumido em
 * ml custa R$ 0,0833/ml: com 2 casas viraria 0,08 e cada banho perderia 0,1% do custo real.
 *
 * Só custo POR UNIDADE muda; valores totais (valor do movimento, valor do estoque, total de linha)
 * continuam em centavos. `purchase_items.unit_cost` é o `vUnCom` da nota (que a NF-e emite com até
 * 10 casas) — entra junto, porque é o custo unitário de compra antes do rateio.
 * `purchase_order_items.unit_cost` (pedido ao fornecedor, digitado) fica em 2 casas.
 *
 * O médio dos produtos com saldo é rederivado do valor do estoque (fonte da verdade) com 4 casas,
 * como o `StockValuation` passa a fazer.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'products' => ['average_cost' => [false, '0'], 'last_cost' => [false, '0']],
        'product_batches' => ['unit_cost' => [false, '0']],
        'purchase_items' => ['unit_cost' => [false, null]],
        'stock_movements' => ['unit_cost' => [false, '0']],
        'sale_items' => ['unit_cost' => [false, '0']],
    ];

    public function up(): void
    {
        $this->retype(4);

        DB::table('products')->where('stock_quantity', '>', 0)->update([
            'average_cost' => DB::raw('ROUND(stock_value / stock_quantity, 4)'),
        ]);
    }

    public function down(): void
    {
        // O PostgreSQL arredonda (meio para longe do zero) ao reduzir a escala do numeric.
        $this->retype(2);
    }

    /**
     * Mantém nulidade e default — `->change()` redefine a coluna inteira.
     */
    private function retype(int $scale): void
    {
        foreach (self::COLUMNS as $tableName => $definitions) {
            Schema::table($tableName, function (Blueprint $table) use ($definitions, $scale): void {
                foreach ($definitions as $column => [$nullable, $default]) {
                    $table->decimal($column, 14, $scale)->nullable($nullable)->default($default)->change();
                }
            });
        }
    }
};
