<?php

namespace App\Services\Commercial\Package;

use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * Valor de cada sessão de um pacote (docs/gap-petflow/PLANO.md, 2.7), pura — sem banco:
 *
 *   total_servico_j  = allocate(preço_pacote, pesos = preço_avulso_j × qtd_j)
 *   valor_sessao_j,k = allocateEqually(total_servico_j, qtd_j)
 *   desconto_pacote  = Σ preço_avulso_j × qtd_j − preço_pacote;  % = desconto / avulso × 100
 *
 * Os dois rateios são pelo maior resto (`Money`), então Σ sessões = preço do pacote, centavo a
 * centavo. Desconto % com 2 casas HALF_UP, `null` sem preço avulso. Pacote vendido ACIMA da soma
 * avulsa dá desconto negativo (ágio) — devolvido como é, a tela decide como mostrar.
 * Casos de referência em `tests/Fixtures/golden/package.json`.
 */
final class PackageSessionPricer
{
    /**
     * @param  list<array{service_id: int, unit_price: string, quantity: int}>  $items
     * @return array{package_price: string, list_total: string, discount_amount: string, discount_percent: string|null, items: list<array{service_id: int, unit_price: string, quantity: int, list_total: string, package_total: string, sessions: list<string>}>}
     */
    public function price(string $packagePrice, array $items): array
    {
        $price = Money::of($packagePrice);
        $listTotals = array_map(fn (array $item): Money => Money::of($item['unit_price'])->times($item['quantity']), $items);
        $listTotal = Money::sum($listTotals);
        $shares = $price->allocate($listTotals);

        return [
            'package_price' => $price->toString(),
            'list_total' => $listTotal->toString(),
            'discount_amount' => $listTotal->minus($price)->toString(),
            'discount_percent' => $this->percent($listTotal->minus($price), $listTotal),
            'items' => array_map(fn (array $item, int $index): array => [
                'service_id' => $item['service_id'],
                'unit_price' => Money::of($item['unit_price'])->toString(),
                'quantity' => $item['quantity'],
                'list_total' => $listTotals[$index]->toString(),
                'package_total' => $shares[$index]->toString(),
                'sessions' => array_map(fn (Money $session): string => $session->toString(), $shares[$index]->allocateEqually($item['quantity'])),
            ], $items, array_keys($items)),
        ];
    }

    private function percent(Money $discount, Money $listTotal): ?string
    {
        if (! $listTotal->isPositive()) {
            return null;
        }

        return (string) $discount->toBigDecimal()->multipliedBy(100)->dividedBy($listTotal->toBigDecimal(), 2, RoundingMode::HalfUp);
    }
}
