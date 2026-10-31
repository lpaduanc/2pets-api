<?php

namespace App\Services\Commercial\Pricing;

use App\Enums\CardFeeReferenceMode;
use App\Services\Commercial\PricingService;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;

/**
 * A conta da simulação de preço (docs/gap-petflow/PLANO.md, 1.4), pura — sem banco: o preço
 * (informado, por margem ou por markup), markup%, margem%, preço mínimo, prejuízo por unidade e
 * alertas. É o que o `PricingSimulator` devolve depois de resolver taxa, imposto e comissão, e o
 * que os casos de `tests/Fixtures/golden/pricing.json` conferem (cópia byte a byte no 2pets-pro).
 */
final class PricingQuoteCalculator
{
    public const COST_SCALE = 4;

    public const PERCENT_SCALE = 4;

    public function __construct(private readonly PricingService $pricing) {}

    /**
     * Exatamente um entre `price`, `margin_percent` e `markup_percent` vem preenchido (o Form
     * Request garante). `$cardFeeMode` só passa adiante: diz de onde veio a taxa do contexto.
     *
     * @param  array{cost: string|int|float, price?: string|int|float|null, margin_percent?: string|int|float|null, markup_percent?: string|int|float|null}  $target
     * @return array{cost: string, price: string, markup_percent: string|null, margin_percent: string|null, minimum_price: string|null, card_fee_reference_percent: string, card_fee_reference_mode: string, tax_percent: string, commission_percent: string, loss_per_unit: string|null, alerts: list<array{code: string, params: array<string, string>}>}
     */
    public function calculate(array $target, PricingContext $context, CardFeeReferenceMode $cardFeeMode): array
    {
        $cost = Decimal::round($target['cost'], self::COST_SCALE);
        $price = $this->priceFor($cost, $target);
        $minimum = $this->pricing->minimumViablePrice($cost, $context);
        $loss = $minimum !== null && $price->isLessThan($minimum) ? $minimum->minus($price) : null;

        return [
            'cost' => (string) $cost,
            'price' => $price->toString(),
            'markup_percent' => $this->pricing->markupPercent($cost, $price->toBigDecimal()),
            'margin_percent' => $this->pricing->marginPercent($cost, $price->toBigDecimal()),
            'minimum_price' => $minimum?->toString(),
            'card_fee_reference_percent' => self::percent($context->cardFeePercent),
            'card_fee_reference_mode' => $cardFeeMode->value,
            'tax_percent' => self::percent($context->taxPercent),
            'commission_percent' => self::percent($context->commissionPercent),
            'loss_per_unit' => $loss?->toString(),
            'alerts' => $this->alerts($cost, $price, $loss, $minimum, $context),
        ];
    }

    /** @param  array<string, mixed>  $target */
    private function priceFor(BigDecimal $cost, array $target): Money
    {
        if (isset($target['price'])) {
            return Money::of($target['price']);
        }

        if (isset($target['margin_percent'])) {
            return $this->pricing->priceForMargin($cost, Decimal::of($target['margin_percent']));
        }

        return $this->pricing->priceForMarkup($cost, Decimal::of($target['markup_percent'] ?? 0));
    }

    /** @return list<array{code: string, params: array<string, string>}> */
    private function alerts(BigDecimal $cost, Money $price, ?Money $loss, ?Money $minimum, PricingContext $context): array
    {
        $raised = [
            PricingAlert::BELOW_MINIMUM->value => $loss === null ? null : ['loss_per_unit' => $loss->toString()],
            PricingAlert::BELOW_COST->value => $price->toBigDecimal()->isLessThan($cost) ? [] : null,
            PricingAlert::NO_CARD_FEE_REFERENCE->value => $context->cardFeePercent === null ? [] : null,
            PricingAlert::NO_TAX_RATE->value => $context->taxPercent === null ? [] : null,
            PricingAlert::MINIMUM_PRICE_UNREACHABLE->value => $minimum === null ? [] : null,
        ];

        $raised = array_filter($raised, fn (?array $params): bool => $params !== null);

        return array_map(
            fn (string $code, array $params): array => ['code' => $code, 'params' => $params],
            array_keys($raised),
            array_values($raised),
        );
    }

    private static function percent(?string $value): string
    {
        return (string) Decimal::round($value, self::PERCENT_SCALE);
    }
}
