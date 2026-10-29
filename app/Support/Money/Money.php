<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Valor em reais, exato, com 2 casas — docs/gap-petflow/PLANO.md, seções 0.1 e 3.
 *
 * Existe porque `float` + `round()` espalhado pelos services erra centavo quando a conta é
 * rateio (desconto entre itens, taxa entre parcelas, frete entre itens): cada parte é
 * arredondada sozinha e a soma das partes deixa de bater com o total. Aqui a regra é:
 *
 * - arredondamento `HALF_UP` uma vez só, no fim do fato monetário (`times`, `percentage`);
 * - todo valor distribuído sai de `allocate`, cuja soma é igual ao total por construção.
 *
 * Imutável. As colunas continuam `decimal(…,2)`: converte na leitura (`Money::of($model->x)`)
 * e na escrita (`->toString()`).
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    private function __construct(private readonly BigDecimal $amount) {}

    /**
     * Valor com mais de 2 casas é arredondado aqui (padrão `HALF_UP`). Preço mínimo, que não
     * pode arredondar para baixo, passa `RoundingMode::Up` (plano, seção 1.4).
     */
    public static function of(self|BigNumber|int|float|string|null $value, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return new self(Decimal::round($value, self::SCALE, $mode));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    public static function ofCents(BigInteger|int $cents): self
    {
        return new self(BigDecimal::ofUnscaledValue($cents, self::SCALE));
    }

    /** @param  iterable<self|BigNumber|int|float|string|null>  $values */
    public static function sum(iterable $values): self
    {
        $total = self::zero();

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    public function plus(self|BigNumber|int|float|string|null $value): self
    {
        return new self($this->amount->plus(self::of($value)->amount));
    }

    public function minus(self|BigNumber|int|float|string|null $value): self
    {
        return new self($this->amount->minus(self::of($value)->amount));
    }

    /** Valor × fator (quantidade, custo unitário de 4 casas…), arredondado uma única vez. */
    public function times(BigNumber|int|float|string $factor, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self($this->amount->multipliedBy(Decimal::of($factor))->toScale(self::SCALE, $mode));
    }

    /** `percentage('2.99')` = 2,99% deste valor. */
    public function percentage(BigNumber|int|float|string $percent, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        $exact = $this->amount->multipliedBy(Decimal::of($percent))->dividedBy(100, self::SCALE + 10, RoundingMode::HalfUp);

        return new self($exact->toScale(self::SCALE, $mode));
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    public function max(self|BigNumber|int|float|string $other): self
    {
        $other = self::of($other);

        return $this->isGreaterThanOrEqualTo($other) ? $this : $other;
    }

    public function min(self|BigNumber|int|float|string $other): self
    {
        $other = self::of($other);

        return $this->isLessThanOrEqualTo($other) ? $this : $other;
    }

    /**
     * Distribui este valor entre partes proporcionais aos pesos, pelo método do maior resto:
     * cada parte recebe o piso de `total × peso / Σpesos` em centavos, e os centavos que sobram
     * vão, um a um, para as partes de maior resto fracionário (empate: maior peso, depois a
     * ordem original). Garantia: a soma das partes é igual ao total, sempre.
     *
     * Pesos negativos são recusados; todos zero dividem em partes iguais. Valor negativo
     * (estorno) é distribuído pelo módulo e devolvido com sinal. As chaves dos pesos são
     * preservadas, para ratear direto por id (`[$itemId => $total]`).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, self|BigNumber|int|float|string>  $weights
     * @return array<TKey, self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Rateio exige ao menos um peso.');
        }

        $integers = self::weightsAsIntegers($weights);
        $sumOfWeights = BigInteger::sum(...array_values($integers));

        if ($sumOfWeights->isZero()) {
            $integers = array_map(fn (): BigInteger => BigInteger::one(), $integers);
            $sumOfWeights = BigInteger::of(count($integers));
        }

        $totalCents = $this->cents();
        $negative = $totalCents->isNegative();
        $totalCents = $totalCents->abs();

        $shares = [];
        $remainders = [];
        $allocated = BigInteger::zero();

        foreach ($integers as $key => $weight) {
            [$quotient, $remainder] = $totalCents->multipliedBy($weight)->quotientAndRemainder($sumOfWeights);
            $shares[$key] = $quotient;
            $remainders[$key] = $remainder;
            $allocated = $allocated->plus($quotient);
        }

        foreach (self::largestRemainderOrder($integers, $remainders, $totalCents->minus($allocated)->toInt()) as $key) {
            $shares[$key] = $shares[$key]->plus(1);
        }

        return array_map(
            fn (BigInteger $cents): self => self::ofCents($negative ? $cents->negated() : $cents),
            $shares,
        );
    }

    /**
     * Partes iguais pelo maior resto: R$ 100,00 em 3 = 33,34 · 33,33 · 33,33 (os centavos extras
     * vão para as primeiras partes). É o rateio de sessões de pacote (plano, seção 2.7).
     *
     * @return list<self>
     */
    public function allocateEqually(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Rateio exige ao menos uma parte.');
        }

        return array_values($this->allocate(array_fill(0, $parts, 1)));
    }

    /**
     * Parcelas iguais truncadas no centavo e TODA a diferença numa parcela só. A adquirente põe
     * a diferença na 1ª parcela (padrão); a parcela de compra a fornecedor, historicamente na
     * última (`InstallmentPlanner`). Diferente de `allocateEqually` quando sobra mais de um
     * centavo: R$ 0,05 em 3 = 0,03 · 0,01 · 0,01, não 0,02 · 0,02 · 0,01.
     *
     * @return list<self>
     */
    public function splitInInstallments(int $count, bool $remainderOnLast = false): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Parcelamento exige ao menos uma parcela.');
        }

        $totalCents = $this->cents();
        $base = $totalCents->quotient($count);
        $remainderIndex = $remainderOnLast ? $count - 1 : 0;
        $installments = [];

        for ($i = 0; $i < $count; $i++) {
            $cents = $i === $remainderIndex ? $totalCents->minus($base->multipliedBy($count - 1)) : $base;
            $installments[] = self::ofCents($cents);
        }

        return $installments;
    }

    public function compareTo(self|BigNumber|int|float|string $other): int
    {
        return $this->amount->compareTo(self::of($other)->amount);
    }

    public function isEqualTo(self|BigNumber|int|float|string $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isGreaterThan(self|BigNumber|int|float|string $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self|BigNumber|int|float|string $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self|BigNumber|int|float|string $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self|BigNumber|int|float|string $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function cents(): BigInteger
    {
        return $this->amount->getUnscaledValue();
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }

    /** `"1234.50"` — formato das colunas `decimal` e da API. */
    public function toString(): string
    {
        return (string) $this->amount;
    }

    /** Só para assinaturas legadas que devolvem `float`; não faça conta com o resultado. */
    public function toFloat(): float
    {
        return $this->amount->toFloat();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * Pesos decimais viram inteiros na mesma escala, para a divisão do rateio ser só de inteiros
     * (sem dízima, sem arredondamento escondido).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, self|BigNumber|int|float|string>  $weights
     * @return array<TKey, BigInteger>
     */
    private static function weightsAsIntegers(array $weights): array
    {
        $decimals = array_map(
            fn (self|BigNumber|int|float|string $weight): BigDecimal => $weight instanceof self ? $weight->amount : Decimal::of($weight),
            $weights,
        );

        foreach ($decimals as $weight) {
            if ($weight->isNegative()) {
                throw new InvalidArgumentException('Peso de rateio não pode ser negativo.');
            }
        }

        $scale = max(array_map(fn (BigDecimal $weight): int => $weight->getScale(), $decimals));

        return array_map(fn (BigDecimal $weight): BigInteger => $weight->toScale($scale)->getUnscaledValue(), $decimals);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, BigInteger>  $weights
     * @param  array<TKey, BigInteger>  $remainders
     * @return list<TKey>
     */
    private static function largestRemainderOrder(array $weights, array $remainders, int $leftover): array
    {
        $keys = array_keys($weights);
        $position = array_flip($keys);

        usort($keys, fn ($a, $b): int => $remainders[$b]->compareTo($remainders[$a])
            ?: $weights[$b]->compareTo($weights[$a])
            ?: $position[$a] <=> $position[$b]);

        return array_slice($keys, 0, $leftover);
    }
}
