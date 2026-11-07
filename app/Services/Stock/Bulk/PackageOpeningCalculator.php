<?php

namespace App\Services\Stock\Bulk;

use App\Services\Stock\AverageCostCalculator;
use App\Services\Stock\StockValuation;
use App\Support\Money\Decimal;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;

/**
 * A conta da abertura de embalagem (docs/gap-petflow/PLANO.md, 3.3), pura — usa a MESMA cadeia
 * de valuation do estoque (`AverageCostCalculator`):
 *
 *   sai do saco:  valor = saída pelo médio do saco (exata, sem resíduo)
 *   entra:        quantidade = q × fator; VALOR = o valor que saiu (custo unitário exibido =
 *                 valor ÷ quantidade, 4 casas — é o custo_médio_do_saco ÷ fator)
 *   granel:       médio novo = (valor que havia + valor que entrou) ÷ saldo novo, 4 casas HALF_UP
 *
 * O valor passa inteiro do saco para o granel: nada aparece ou some no estoque, mesmo quando o
 * custo por kg não divide exato (o médio de 4 casas é derivado, o valor é que é a verdade).
 * Casos de referência em `tests/Fixtures/golden/stock-conversion.json`.
 */
final class PackageOpeningCalculator
{
    public function __construct(private readonly AverageCostCalculator $averageCost = new AverageCostCalculator) {}

    /** @return array{exit_value: string, entered_quantity: string, entering_unit_cost: string, from_after: StockValuation, to_after: StockValuation} */
    public function open(StockValuation $from, StockValuation $to, Quantity $quantity, string $factor): array
    {
        [$fromAfter, $exitValue] = $this->averageCost->afterExit($from, $quantity);
        $entered = self::entered($quantity, $factor);
        [$toAfter] = $this->averageCost->afterEntry($to, $entered, $exitValue);

        return [
            'exit_value' => (string) $exitValue,
            'entered_quantity' => $entered->toString(),
            'entering_unit_cost' => (string) $exitValue->dividedBy($entered->toBigDecimal(), StockValuation::COST_SCALE, RoundingMode::HalfUp),
            'from_after' => $fromAfter,
            'to_after' => $toAfter,
        ];
    }

    /** q × fator; mais de 4 casas (o limite da quantidade de estoque) é recusado por quem chama. */
    public static function entered(Quantity $quantity, string $factor): Quantity
    {
        return Quantity::of($quantity->toBigDecimal()->multipliedBy(Decimal::of($factor))->strippedOfTrailingZeros());
    }
}
