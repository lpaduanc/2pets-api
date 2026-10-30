<?php

namespace App\Services\Purchase;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Uma linha da compra no cálculo do custo real (docs/gap-petflow/PLANO.md, 1.2). Imutável: o
 * `PurchaseCostAllocator` troca o desconto e as partes rateadas com `with()`.
 *
 *   custo_total = linha − desconto + frete + seguro + outras + IPI + ICMS-ST
 *   custo_unit  = custo_total / (quantidade × fator)   — por unidade de ESTOQUE, 4 casas
 */
final class PurchaseCostLine
{
    /** Despesas que somam ao custo da linha, na ordem em que a resposta as mostra. */
    public const EXPENSE_FIELDS = ['freight_share', 'insurance_share', 'other_share', 'ipi_amount', 'icms_st_amount'];

    public const DISCOUNT = 'discount';

    private const FACTOR_SCALE = 3;

    private const UNIT_COST_SCALE = 4;

    /** @param  array<string, Money>  $amounts  desconto + despesas */
    private function __construct(
        private readonly BigDecimal $conversionFactor,
        private readonly Quantity $stockQuantity,
        private readonly Money $gross,
        private readonly array $amounts,
    ) {}

    /** @param  array<string, mixed>  $item  quantity, unit_cost, gross_total?, discount?, despesas?, conversion_factor? */
    public static function fromInput(array $item, int $index): self
    {
        $quantity = Quantity::of($item['quantity']);
        $factor = Decimal::of($item['conversion_factor'] ?? 1)->toScale(self::FACTOR_SCALE, RoundingMode::Unnecessary);
        $amounts = [];

        foreach ([self::DISCOUNT, ...self::EXPENSE_FIELDS] as $field) {
            $amounts[$field] = Money::of($item[$field] ?? 0);
        }

        return new self($factor, self::stockQuantity($quantity, $factor, $index), self::grossFrom($quantity, $item), $amounts);
    }

    public function with(string $field, Money $amount): self
    {
        return new self($this->conversionFactor, $this->stockQuantity, $this->gross, [$field => $amount] + $this->amounts);
    }

    public function amount(string $field): Money
    {
        return $this->amounts[$field];
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    /** Peso do rateio de despesa do cabeçalho: valor da linha − desconto do item. */
    public function net(): Money
    {
        return $this->gross->minus($this->amounts[self::DISCOUNT]);
    }

    public function landedTotal(): Money
    {
        return $this->net()->plus(Money::sum(array_map(fn (string $field): Money => $this->amounts[$field], self::EXPENSE_FIELDS)));
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'gross_total' => $this->gross->toString(),
            self::DISCOUNT => $this->amounts[self::DISCOUNT]->toString(),
            'total_cost' => $this->net()->toString(),
            ...array_map(fn (string $field): string => $this->amounts[$field]->toString(), array_combine(self::EXPENSE_FIELDS, self::EXPENSE_FIELDS)),
            'conversion_factor' => (string) $this->conversionFactor,
            'stock_quantity' => $this->stockQuantity->toString(),
            'landed_total_cost' => $this->landedTotal()->toString(),
            'landed_unit_cost' => (string) $this->landedUnitCost(),
        ];
    }

    private function landedUnitCost(): BigDecimal
    {
        if (! $this->stockQuantity->isPositive()) {
            return BigDecimal::zero()->toScale(self::UNIT_COST_SCALE);
        }

        return $this->landedTotal()->toBigDecimal()->dividedBy($this->stockQuantity->toBigDecimal(), self::UNIT_COST_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Bruto da linha: o da nota (`gross_total`, XML) quando vem; senão quantidade × custo,
     * virando centavo uma vez. Nunca recalcular o que a nota já fixou.
     *
     * @param  array<string, mixed>  $item
     */
    private static function grossFrom(Quantity $quantity, array $item): Money
    {
        if (isset($item['gross_total'])) {
            return Money::of($item['gross_total']);
        }

        return Money::of($quantity->toBigDecimal()->multipliedBy(Decimal::of($item['unit_cost'])));
    }

    /** Quantidade em unidade de estoque: 5 caixas × 12 = 60 un. Até 4 casas, sem arredondar. */
    private static function stockQuantity(Quantity $quantity, BigDecimal $factor, int $index): Quantity
    {
        try {
            return Quantity::of($quantity->toBigDecimal()->multipliedBy($factor));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                "items.{$index}.conversion_factor" => sprintf('Item %d: quantidade × fator de conversão passa de 4 casas decimais.', $index + 1),
            ]);
        }
    }
}
