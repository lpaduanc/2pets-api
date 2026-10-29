<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Quantidade de estoque exata, até 4 casas (mesmo limite do `qCom` da NF-e) — docs/gap-petflow/
 * PLANO.md, seção 0.2.
 *
 * Diferente de `Money`, NUNCA arredonda: quantidade é o que o profissional pesou ou contou, e
 * arredondar 1,25005 kg para 1,2501 kg criaria estoque que não existe. Valor com mais casas do
 * que o suportado é recusado com exceção, para o erro aparecer na entrada e não no saldo.
 */
final class Quantity implements JsonSerializable, Stringable
{
    public const SCALE = 4;

    private function __construct(private readonly BigDecimal $value) {}

    public static function of(self|BigNumber|int|float|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $decimal = Decimal::of($value)->strippedOfTrailingZeros();

        if ($decimal->getScale() > self::SCALE) {
            throw new InvalidArgumentException(sprintf('Quantidade %s tem mais de %d casas decimais.', $decimal, self::SCALE));
        }

        return new self($decimal->toScale(self::SCALE));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    public function plus(self|int|string $other): self
    {
        return new self($this->value->plus(self::of($other)->value));
    }

    public function minus(self|int|string $other): self
    {
        return new self($this->value->minus(self::of($other)->value));
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    public function abs(): self
    {
        return new self($this->value->abs());
    }

    public function min(self|int|string $other): self
    {
        $other = self::of($other);

        return $this->isLessThanOrEqualTo($other) ? $this : $other;
    }

    public function max(self|int|string $other): self
    {
        $other = self::of($other);

        return $this->isGreaterThanOrEqualTo($other) ? $this : $other;
    }

    public function compareTo(self|int|string $other): int
    {
        return $this->value->compareTo(self::of($other)->value);
    }

    public function isEqualTo(self|int|string $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isGreaterThan(self|int|string $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self|int|string $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self|int|string $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self|int|string $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    /** Casas realmente usadas: `1.2500` → 2. Base da regra "produto de unidade não aceita fração". */
    public function decimalPlaces(): int
    {
        return max(0, $this->value->strippedOfTrailingZeros()->getScale());
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }

    /** `"1.2500"` — formato da coluna `decimal(15,4)` e da API. */
    public function toString(): string
    {
        return (string) $this->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
