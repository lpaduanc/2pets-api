<?php

namespace App\Services\Commercial\Pricing;

use App\Models\SaleItem;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Venda abaixo do custo, linha a linha (docs/gap-petflow/PLANO.md, 1.4). Decisão do dono do
 * produto: bloqueio ABSOLUTO, cobrado na FINALIZAÇÃO (recebimento ou `finalize`) — linha com
 * líquido menor que quantidade × custo congelado (`SaleItem::isBelowCost`) é 422 para qualquer
 * cargo. Lançar e editar não bloqueiam: a linha aparece como `below_cost` e a saída é corrigir o
 * preço ou marcá-la como brinde, cortesia ou retorno (que nunca é abaixo do custo).
 */
final class BelowCostLineGuard
{
    public function assertCoversCost(SaleItem $item, Money $net, string $field): void
    {
        if (! $item->isBelowCost($net)) {
            return;
        }

        throw ValidationException::withMessages([$field => __('pricing.below_cost_forbidden', [
            'item' => $item->description,
            'net' => self::display(self::perUnit($net, $item)),
            'cost' => self::display(Decimal::of($item->unit_cost)),
        ])]);
    }

    private static function perUnit(Money $net, SaleItem $item): BigDecimal
    {
        $quantity = Decimal::of($item->quantity);

        return $quantity->isPositive() ? $net->toBigDecimal()->dividedBy($quantity, Money::SCALE, RoundingMode::HalfUp) : $net->toBigDecimal();
    }

    /** "12,5000" → "12,50": mensagem para gente, não para conta. */
    private static function display(BigDecimal $value): string
    {
        return number_format($value->toScale(2, RoundingMode::HalfUp)->toFloat(), 2, ',', '.');
    }
}
