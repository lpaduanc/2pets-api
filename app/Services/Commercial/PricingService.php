<?php

namespace App\Services\Commercial;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Services\Commercial\Pricing\PricingContext;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Markup é primeira classe no SimplesVet: aparece por produto na listagem, é editável, e a
 * compra recalcula o preço a partir dele (doc 08). Este service é a ÚNICA definição de como
 * custo, markup e preço se convertem entre si — controller e model nunca fazem a conta.
 *
 * Convenção adotada: markup é percentual SOBRE O CUSTO (`preço = custo × (1 + markup/100)`),
 * não margem sobre o preço de venda. Custo 10,00 com markup 40% dá 14,00, que é exatamente o
 * critério de aceite do doc 08. As duas leituras existem no mercado e dão números diferentes
 * (margem de 40% sobre 10,00 daria 16,67); a escolha aqui segue o número do documento.
 *
 * Precificação exata (docs/gap-petflow/PLANO.md, 1.4): `markupPercent`, `marginPercent`,
 * `priceForMarkup`, `priceForMargin` e `minimumViablePrice` são em BigDecimal, sem float — os
 * métodos `float` acima deles continuam só para os chamadores legados. Percentual 4 casas
 * HALF_UP; preço 2 casas HALF_UP; preço mínimo 2 casas para CIMA (arredondar para baixo devolveria
 * um preço que dá prejuízo). Casos de referência em `tests/Fixtures/golden/pricing.json`.
 */
final class PricingService
{
    private const SCALE = 4;

    private const HUNDRED = 100;

    /**
     * Markup implícito num par custo/preço. Custo zero devolve `null`: não existe percentual
     * sobre zero, e devolver 0 mentiria dizendo "sem margem" para um item doado ou ainda sem
     * custo lançado.
     */
    public function markupFromCost(float $cost, float $price): ?float
    {
        if ($cost <= 0.0) {
            return null;
        }

        return round((($price - $cost) / $cost) * 100, self::SCALE);
    }

    public function priceFromMarkup(float $cost, float $markupPercent): float
    {
        return round($cost * (1 + ($markupPercent / 100)), 2);
    }

    /**
     * Preenche markup/preço de um payload de produto a partir do que veio informado.
     *
     * Regra de precedência, deliberada: quem informa PREÇO manda, e o markup é recalculado a
     * partir dele. Só quando o preço não vem é que o markup (do payload ou herdado do grupo)
     * gera o preço. É o comportamento do critério de aceite do doc 08 — "alterar o preço para
     * 15,00 recalcula o markup para 50%" —, e evita o vaivém em que salvar a tela duas vezes
     * produz preços diferentes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function resolvePricing(array $attributes, ?ProductGroup $group = null): array
    {
        $cost = (float) ($attributes['average_cost'] ?? $attributes['last_cost'] ?? 0);
        $hasPrice = isset($attributes['price']) && $attributes['price'] !== null && $attributes['price'] !== '';
        $markup = $attributes['markup_percent'] ?? null;

        if ($markup === null || $markup === '') {
            $markup = $group?->effectiveMarkupPercent();
        }

        if ($hasPrice) {
            $attributes['price'] = round((float) $attributes['price'], 2);
            $attributes['markup_percent'] = $this->markupFromCost($cost, (float) $attributes['price']);

            return $attributes;
        }

        if ($markup !== null && $cost > 0.0) {
            $attributes['markup_percent'] = round((float) $markup, self::SCALE);
            $attributes['price'] = $this->priceFromMarkup($cost, (float) $markup);

            return $attributes;
        }

        // Sem preço e sem meio de derivá-lo: deixa como está e o Form Request reprova
        // (`price` é obrigatório quando não há custo+markup). Não inventamos zero aqui —
        // produto a R$ 0,00 no balcão é pior do que produto que não salva.
        return $attributes;
    }

    /**
     * Reprecificação a partir de um custo novo (entrada de compra, doc 06). Preserva o markup
     * vigente do produto; sem markup, o preço fica intocado — subir o custo nunca pode baixar
     * um preço definido à mão.
     */
    public function repriceForNewCost(Product $product, float $newCost): float
    {
        $markup = $product->markup_percent;

        if ($markup === null) {
            return (float) $product->price;
        }

        return $this->priceFromMarkup($newCost, (float) $markup);
    }

    /** markup% = (P − C) / C × 100; `null` com custo zero (não há percentual sobre zero). */
    public function markupPercent(BigNumber|string $cost, BigNumber|string $price): ?string
    {
        return self::percentOfBase(Decimal::of($price)->minus(Decimal::of($cost)), Decimal::of($cost));
    }

    /** margem% = (P − C) / P × 100; `null` com preço zero. */
    public function marginPercent(BigNumber|string $cost, BigNumber|string $price): ?string
    {
        return self::percentOfBase(Decimal::of($price)->minus(Decimal::of($cost)), Decimal::of($price));
    }

    /** P = C × (1 + markup/100), 2 casas HALF_UP. */
    public function priceForMarkup(BigNumber|string $cost, BigNumber|string $markupPercent): Money
    {
        $factor = BigDecimal::one()->plus(Decimal::of($markupPercent)->dividedByExact(self::HUNDRED));

        return Money::of(Decimal::of($cost)->multipliedBy($factor));
    }

    /** P = C / (1 − margem/100), 2 casas HALF_UP. Margem ≥ 100% não tem preço. */
    public function priceForMargin(BigNumber|string $cost, BigNumber|string $marginPercent): Money
    {
        $denominator = BigDecimal::one()->minus(Decimal::of($marginPercent)->dividedByExact(self::HUNDRED));

        if (! $denominator->isPositive()) {
            throw new InvalidArgumentException('Margem precisa ser menor que 100%.');
        }

        return Money::of(Decimal::of($cost)->dividedBy($denominator, Money::SCALE, RoundingMode::HalfUp));
    }

    /**
     * P_mínimo = round_UP(C / (1 − taxa_cartão_ref − imposto% − comissão%)). Recebe o item (custo
     * de `unitCost()` e comissão do cadastro, salvo se o contexto trouxer outra) ou o custo cru.
     * `null` quando as deduções somam 100% ou mais: nenhum preço cobre o custo.
     */
    public function minimumViablePrice(Product|Service|BigNumber|string $item, PricingContext $context): ?Money
    {
        $isItem = $item instanceof Product || $item instanceof Service;
        $cost = Decimal::of($isItem ? $item->unitCost() : $item);
        $commission = $context->commissionPercent ?? ($isItem ? $item->commission_percent : null);
        $denominator = BigDecimal::one()->minus($context->deductionsPercent($commission)->dividedByExact(self::HUNDRED));

        if (! $denominator->isPositive()) {
            return null;
        }

        return Money::of($cost->dividedBy($denominator, Money::SCALE, RoundingMode::Up));
    }

    private static function percentOfBase(BigDecimal $part, BigDecimal $base): ?string
    {
        if ($base->isZero()) {
            return null;
        }

        return (string) $part->multipliedBy(self::HUNDRED)->dividedBy($base, self::SCALE, RoundingMode::HalfUp);
    }
}
