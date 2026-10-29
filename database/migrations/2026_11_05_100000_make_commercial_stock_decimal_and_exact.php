<?php

use App\Enums\StockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estoque comercial decimal e valor de estoque exato em centavos — docs/gap-petflow/PLANO.md,
 * seção 0.2, e regra do dono do produto (2026-10-29): todo valor monetário tem 2 casas, e nenhum
 * centavo some nem aparece — os relatórios diários, mensais e consolidados têm que fechar.
 *
 * - Quantidade: `integer` → `decimal(15,4)` (limite do `qCom` da NF-e). Granel (1,250 kg) e
 *   insumo fracionado passam a existir; antes, `SaleService` baixava `ceil()` da quantidade
 *   vendida — 1,25 kg de ração tirava 2 kg do estoque. Quantidade não é dinheiro.
 * - Custo unitário: `decimal(…,4)` → `decimal(14,2)`. Preço já era de 2 casas.
 * - `products.stock_value decimal(14,2)`: valor do estoque em centavos, fonte da verdade; o custo
 *   médio é derivado (valor ÷ saldo, em centavos, só para exibir e precificar). Toda saída tira
 *   centavos do valor, e sair tudo leva o valor inteiro: Σ entradas = Σ saídas + valor em estoque.
 * - `products.decimal_places_allowed` (0–4): produto vendido por unidade recusa fração na entrada.
 *   A unidade continua sendo `unit_of_sale` (texto livre da nota: UN, KG, CX…); o backfill dá 3
 *   casas às unidades de peso/volume/medida e 0 ao resto.
 *
 * O valor do estoque é calculado ANTES de o custo médio perder as 4 casas: vem do saldo real
 * (saldo × médio antigo, arredondado uma vez), não de um médio já arredondado.
 *
 * `down()` volta os tipos antigos e PERDE a fração da quantidade.
 */
return new class extends Migration
{
    // Cópia congelada de `Product::FRACTIONAL_UNITS`: migration não lê constante de model, que
    // pode mudar depois e reescrever o passado.
    private const FRACTIONAL_UNITS = ['KG', 'G', 'MG', 'L', 'ML', 'M', 'M2', 'CM'];

    private const QUANTITY_COLUMNS = [
        'products' => ['stock_quantity' => [false, '0'], 'min_stock' => [false, '0'], 'max_stock' => [true, null]],
        'product_batches' => ['quantity' => [false, '0']],
        'stock_movements' => ['quantity' => [false, null], 'balance_after' => [false, null]],
        'stock_count_items' => ['system_quantity' => [false, null], 'counted_quantity' => [true, null], 'difference' => [true, null]],
        'purchase_items' => ['quantity' => [false, null]],
        'purchase_order_items' => ['quantity' => [false, null], 'received_quantity' => [false, '0']],
        'sale_return_items' => ['quantity' => [false, null]],
        'sale_items' => ['quantity' => [false, '1']],
    ];

    /** Custos que tinham 4 casas; o tipo antigo de cada um volta no `down()`. */
    private const COST_COLUMNS = [
        'products' => ['average_cost' => [false, '0'], 'last_cost' => [false, '0']],
        'product_batches' => ['unit_cost' => [false, '0']],
        'stock_movements' => ['unit_cost' => [false, '0']],
        'purchase_items' => ['unit_cost' => [false, null]],
        'purchase_order_items' => ['unit_cost' => [false, '0']],
        'sale_items' => ['unit_cost' => [false, '0']],
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('stock_value', 14, 2)->default(0)->after('average_cost');
            $table->unsignedSmallInteger('decimal_places_allowed')->default(0)->after('unit_of_sale');
        });

        // Saldo × médio de 4 casas, arredondado uma vez. Saldo negativo dá valor negativo:
        // unidades devidas, já lançadas no custo da venda pelo médio (`AverageCostCalculator`).
        DB::table('products')->where('stock_quantity', '!=', 0)->update([
            'stock_value' => DB::raw('ROUND(stock_quantity * average_cost, 2)'),
        ]);

        $this->retype(self::QUANTITY_COLUMNS, fn (Blueprint $table, string $column) => $table->decimal($column, 15, 4));
        $this->retype(self::COST_COLUMNS, fn (Blueprint $table, string $column) => $table->decimal($column, 14, 2));

        // Médio derivado do valor, como o `StockValuation` passa a fazer daqui em diante.
        DB::table('products')->where('stock_quantity', '>', 0)->update([
            'average_cost' => DB::raw('ROUND(stock_value / stock_quantity, 2)'),
        ]);

        DB::table('products')
            ->whereIn(DB::raw('upper(trim(unit_of_sale))'), self::FRACTIONAL_UNITS)
            ->update(['decimal_places_allowed' => 3]);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_decimal_places_allowed_check CHECK (decimal_places_allowed BETWEEN 0 AND 4)');
            $this->replaceMovementChecks(StockMovementType::values(), "quantity > 0 OR (quantity = 0 AND type IN ('cost_variance_in','cost_variance_out'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_decimal_places_allowed_check');
            DB::table('stock_movements')->whereIn('type', ['cost_variance_in', 'cost_variance_out'])->delete();
            $types = array_values(array_diff(StockMovementType::values(), ['cost_variance_in', 'cost_variance_out']));
            $this->replaceMovementChecks($types, 'quantity > 0');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['stock_value', 'decimal_places_allowed']);
        });

        $this->retype(['sale_items' => ['quantity' => [false, '1']]], fn (Blueprint $table, string $column) => $table->decimal($column, 12, 3));
        $quantities = self::QUANTITY_COLUMNS;
        unset($quantities['sale_items']);
        $this->retype($quantities, fn (Blueprint $table, string $column) => $table->integer($column));

        $this->retype(['sale_items' => ['unit_cost' => [false, '0']]], fn (Blueprint $table, string $column) => $table->decimal($column, 14, 4));
        $costs = self::COST_COLUMNS;
        unset($costs['sale_items']);
        $this->retype($costs, fn (Blueprint $table, string $column) => $table->decimal($column, 12, 4));
    }

    /**
     * Movimento de ajuste de custo (`cost_variance_*`) tem quantidade zero: é só valor.
     *
     * @param  list<string>  $types
     */
    private function replaceMovementChecks(array $types, string $quantityRule): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_type_check');
        DB::statement(sprintf("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('%s'))", implode("','", $types)));
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_quantity_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK ({$quantityRule})");
    }

    /**
     * Reaplica o tipo mantendo nulidade e default — `->change()` do Laravel 12 redefine a coluna
     * inteira, e esquecer o modificador derrubaria o `NOT NULL`/`DEFAULT` que já existia.
     *
     * @param  array<string, array<string, array{0: bool, 1: string|null}>>  $columns
     * @param  callable(Blueprint, string): \Illuminate\Database\Schema\ColumnDefinition  $type
     */
    private function retype(array $columns, callable $type): void
    {
        foreach ($columns as $tableName => $definitions) {
            Schema::table($tableName, function (Blueprint $table) use ($definitions, $type): void {
                foreach ($definitions as $column => [$nullable, $default]) {
                    $type($table, $column)->nullable($nullable)->default($default)->change();
                }
            });
        }
    }
};
