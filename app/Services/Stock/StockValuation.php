<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Support\Money\Decimal;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;

/**
 * Posição de estoque de um produto: saldo, valor do estoque em centavos e custo médio.
 *
 * O VALOR é a fonte da verdade (`products.stock_value`, 2 casas); o custo médio (`average_cost`,
 * 2 casas) é derivado dele, para exibir e precificar. Guardar só o médio e recalcular o valor como
 * `saldo × médio` erraria um centavo aqui e outro ali a cada movimento — e o custo das vendas
 * deixaria de bater com o que foi comprado. Aqui, o que entra de valor sai inteiro: cada saída tira
 * centavos do valor, e zerou o estoque, o valor é zero exato.
 */
final readonly class StockValuation
{
    public const VALUE_SCALE = 2;

    public const COST_SCALE = 2;

    public function __construct(
        public Quantity $quantity,
        public BigDecimal $value,
        public BigDecimal $averageCost,
    ) {}

    public static function of(Product $product): self
    {
        return new self(
            Quantity::of($product->stock_quantity),
            Decimal::of($product->stock_value)->toScale(self::VALUE_SCALE),
            Decimal::of($product->average_cost)->toScale(self::COST_SCALE),
        );
    }

    /** @return array{stock_quantity: string, stock_value: string, average_cost: string} */
    public function toAttributes(): array
    {
        return [
            'stock_quantity' => $this->quantity->toString(),
            'stock_value' => (string) $this->value,
            'average_cost' => (string) $this->averageCost,
        ];
    }
}
