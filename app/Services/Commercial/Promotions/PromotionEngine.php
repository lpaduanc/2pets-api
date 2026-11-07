<?php

namespace App\Services\Commercial\Promotions;

use App\Support\Money\Decimal;
use App\Support\Money\Money;

/**
 * Motor de promoções (docs/gap-petflow/PLANO.md, 3.2), determinístico e puro — sem banco.
 *
 * Entrada: as linhas do carrinho (`id`, `sellable_type` product|service, `sellable_id`,
 * `group_id`, `brand_id`, `unit_price`, `quantity`) e as promoções vigentes (`id`, `name`,
 * `type`, `priority`, `stackable`, `config`). Saída: o desconto de promoção por linha.
 *
 *  1. Ordem: maior `priority` primeiro; empate, o maior desconto potencial (calculado sobre o
 *     carrinho inteiro); empate, o menor id.
 *  2. Cada UNIDADE entra em no máximo uma promoção não cumulativa (a primeira na ordem que a
 *     usar). Promoção cumulativa (`stackable`) ignora esse consumo e desconta sobre o valor que
 *     ainda resta da unidade, depois das anteriores — nunca leva a unidade abaixo de zero.
 *  3. Leve X pague Y: conjuntos = floor(elegíveis / X), grátis = conjuntos × (X − Y); as unidades
 *     grátis são as MAIS BARATAS (empate: a linha de menor id). Consome as grátis e as próximas
 *     mais baratas que completam os conjuntos.
 *  4. Venda casada: conjuntos = min floor(qtd_j / req_j); desconto = conjuntos × (% sobre Σ req_j
 *     × preço_j, ou valor fixo por conjunto), um arredondamento só, rateado pelas unidades usadas
 *     pelo valor (`Money::allocate`).
 *  5. Categoria (grupo) / marca: % por linha, sobre o valor que resta das unidades elegíveis,
 *     HALF_UP por linha.
 * Quantidade fracionada (granel) entra como UM bloco indivisível: vale para %, nunca para "leve X"
 * nem para venda casada. Casos de referência em `tests/Fixtures/golden/promotion.json`.
 */
final class PromotionEngine
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $promotions
     * @return array{lines: array<int, array{promotion_id: int|null, promotion_name: string|null, promotion_discount: string, promotions: list<array{promotion_id: int, promotion_name: string, discount: string}>}>, total_promotion_discount: string}
     */
    public function evaluate(array $lines, array $promotions): array
    {
        $units = $this->units($lines);
        $ordered = $this->order($units, $promotions);
        $applied = [];

        foreach ($ordered as $promotion) {
            $discounts = $this->apply($promotion, $units, consume: ! $promotion['stackable']);

            foreach ($discounts as $unitKey => $amount) {
                $units[$unitKey]['remaining'] = $units[$unitKey]['remaining']->minus($amount);
                $applied[$units[$unitKey]['line']][$promotion['id']] = ($applied[$units[$unitKey]['line']][$promotion['id']] ?? Money::zero())->plus($amount);
            }
        }

        return $this->result($lines, $promotions, $applied);
    }

    /**
     * Desconto que a promoção daria sozinha, no carrinho inteiro (para ordenar e simular).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $promotion
     */
    public function potential(array $lines, array $promotion): Money
    {
        $units = $this->units($lines);

        return Money::sum($this->apply($promotion, $units, consume: false));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, array<string, mixed>>
     */
    private function units(array $lines): array
    {
        $units = [];

        foreach ($lines as $line) {
            $quantity = Decimal::of($line['quantity']);
            $whole = $quantity->isEqualTo($quantity->toScale(0, \Brick\Math\RoundingMode::Down));
            $count = $whole ? $quantity->toInt() : 1;
            $value = $whole ? Money::of($line['unit_price']) : Money::of($quantity->multipliedBy(Decimal::of($line['unit_price'])));

            for ($i = 0; $i < $count; $i++) {
                $units[$line['id'].':'.$i] = $line + ['line' => $line['id'], 'value' => $value, 'remaining' => $value, 'whole' => $whole, 'consumed' => false];
            }
        }

        return $units;
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  list<array<string, mixed>>  $promotions
     * @return list<array<string, mixed>>
     */
    private function order(array $units, array $promotions): array
    {
        $withPotential = array_map(fn (array $p): array => $p + ['potential' => Money::sum($this->apply($p, $units, consume: false))], $promotions);

        usort($withPotential, fn (array $a, array $b): int => [$b['priority'], $b['potential']->toBigDecimal(), $a['id']] <=> [$a['priority'], $a['potential']->toBigDecimal(), $b['id']]);

        return $withPotential;
    }

    /**
     * Desconto por unidade. Com `$consume`, só usa unidades livres e as marca como usadas.
     *
     * @param  array<string, mixed>  $promotion
     * @param  array<string, array<string, mixed>>  $units
     * @return array<string, Money>
     */
    private function apply(array $promotion, array &$units, bool $consume): array
    {
        $available = array_filter($units, fn (array $u): bool => ! ($consume && $u['consumed']) && $u['remaining']->isPositive());
        $config = $promotion['config'];

        [$discounts, $used] = match ($promotion['type']) {
            'buy_x_pay_y' => $this->buyXPayY($available, $config),
            'bundle' => $this->bundle($available, $config),
            'category_discount' => $this->percent(array_filter($available, fn (array $u): bool => (string) ($u['group_id'] ?? '') === (string) $config['group_id']), $config['percent']),
            'brand_discount' => $this->percent(array_filter($available, fn (array $u): bool => $u['sellable_type'] === 'product' && (string) ($u['brand_id'] ?? '') === (string) $config['brand_id']), $config['percent']),
            default => [[], []],
        };

        if ($consume) {
            foreach ($used as $key) {
                $units[$key]['consumed'] = true;
            }
        }

        return array_filter($discounts, fn (Money $m): bool => $m->isPositive());
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, Money>, 1: list<string>}
     */
    private function buyXPayY(array $units, array $config): array
    {
        $buy = (int) $config['buy'];
        $pay = (int) $config['pay'];
        $eligible = array_filter($units, fn (array $u): bool => $u['whole'] && $this->matchesTargets($u, $config['targets'] ?? []));
        $sets = $buy > 0 ? intdiv(count($eligible), $buy) : 0;

        if ($sets === 0 || $pay >= $buy) {
            return [[], []];
        }

        uksort($eligible, fn (string $a, string $b): int => [$eligible[$a]['remaining']->toBigDecimal(), $eligible[$a]['line'], $a] <=> [$eligible[$b]['remaining']->toBigDecimal(), $eligible[$b]['line'], $b]);
        $keys = array_keys($eligible);
        $free = array_slice($keys, 0, $sets * ($buy - $pay));

        return [array_combine($free, array_map(fn (string $k): Money => $eligible[$k]['remaining'], $free)), array_slice($keys, 0, $sets * $buy)];
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, Money>, 1: list<string>}
     */
    private function bundle(array $units, array $config): array
    {
        $pools = [];
        $sets = PHP_INT_MAX;

        foreach ($config['items'] as $index => $item) {
            $pool = array_keys(array_filter($units, fn (array $u): bool => $u['whole'] && $u['sellable_type'] === $item['sellable_type'] && (int) $u['sellable_id'] === (int) $item['sellable_id']));
            sort($pool);
            $pools[$index] = $pool;
            $sets = min($sets, intdiv(count($pool), max(1, (int) $item['quantity'])));
        }

        if ($pools === [] || $sets === 0 || $sets === PHP_INT_MAX) {
            return [[], []];
        }

        $used = [];
        foreach ($config['items'] as $index => $item) {
            $used = [...$used, ...array_slice($pools[$index], 0, $sets * (int) $item['quantity'])];
        }

        $weights = array_combine($used, array_map(fn (string $k): Money => $units[$k]['remaining'], $used));
        $base = Money::sum($weights);
        $total = $config['discount_type'] === 'percent'
            ? $base->percentage(Decimal::of($config['discount_value']))
            : Money::of($config['discount_value'])->times($sets)->min($base);

        return [$total->allocate($weights), $used];
    }

    /**
     * % por linha sobre o que resta das unidades elegíveis, HALF_UP por linha; rateado entre as
     * unidades da linha pelo valor (para o consumo por unidade continuar exato).
     *
     * @param  array<string, array<string, mixed>>  $units
     * @return array{0: array<string, Money>, 1: list<string>}
     */
    private function percent(array $units, mixed $percent): array
    {
        $discounts = [];

        foreach (collect($units)->groupBy('line', preserveKeys: true) as $lineUnits) {
            $weights = $lineUnits->map(fn (array $u): Money => $u['remaining'])->all();
            $discounts += Money::sum($weights)->percentage(Decimal::of($percent))->allocate($weights);
        }

        return [$discounts, array_keys($units)];
    }

    /** @param  list<array{type: string, id: int}>  $targets */
    private function matchesTargets(array $unit, array $targets): bool
    {
        foreach ($targets as $target) {
            $hit = match ($target['type']) {
                'product' => $unit['sellable_type'] === 'product' && (int) $unit['sellable_id'] === (int) $target['id'],
                'service' => $unit['sellable_type'] === 'service' && (int) $unit['sellable_id'] === (int) $target['id'],
                'group' => (string) ($unit['group_id'] ?? '') === (string) $target['id'],
                'brand' => $unit['sellable_type'] === 'product' && (string) ($unit['brand_id'] ?? '') === (string) $target['id'],
                default => false,
            };

            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $promotions
     * @param  array<int|string, array<int, Money>>  $applied
     * @return array<string, mixed>
     */
    private function result(array $lines, array $promotions, array $applied): array
    {
        $names = array_column($promotions, 'name', 'id');
        $out = [];

        foreach ($lines as $line) {
            $byPromotion = $applied[$line['id']] ?? [];
            $total = Money::sum($byPromotion);
            $main = null;

            // A promoção que mais descontou na linha (empate: a que aplicou primeiro).
            foreach ($byPromotion as $id => $amount) {
                if ($main === null || $amount->isGreaterThan($byPromotion[$main])) {
                    $main = $id;
                }
            }
            $out[$line['id']] = [
                'promotion_id' => $main === null ? null : (int) $main,
                'promotion_name' => $main === null ? null : $names[$main],
                'promotion_discount' => $total->toString(),
                'promotions' => array_map(fn (int $id, Money $m): array => ['promotion_id' => $id, 'promotion_name' => $names[$id], 'discount' => $m->toString()], array_keys($byPromotion), $byPromotion),
            ];
        }

        return ['lines' => $out, 'total_promotion_discount' => Money::sum(array_column($out, 'promotion_discount'))->toString()];
    }
}
