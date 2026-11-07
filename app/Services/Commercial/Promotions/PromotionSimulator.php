<?php

namespace App\Services\Commercial\Promotions;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Margem de uma promoção no cadastro (PLANO 3.2, `POST promotions/simulate`) e conferência de que
 * os alvos do `config` são da organização (`assertScope`).
 *
 * Carrinho simulado = o MÍNIMO que dispara a promoção, no pior caso:
 *  - leve X pague Y: X unidades de cada produto/serviço-alvo; alvo grupo/marca = o item ativo de
 *    MENOR margem do grupo/marca;
 *  - venda casada: um conjunto;
 *  - categoria/marca: 1 unidade de cada item ativo do grupo/marca.
 * MC = venda − custo (custo médio do produto, custo do serviço pela ficha); MC% = MC ÷ venda.
 * `alert` quando a MC fica negativa. Sem imposto, taxa e comissão: é a margem da promoção.
 */
final class PromotionSimulator
{
    public function __construct(
        private readonly PromotionEngine $engine,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $promotion
     * @return array<string, mixed>
     */
    public function simulate(User $user, array $promotion): array
    {
        $this->assertScope($user, $promotion['type'], $promotion['config']);
        $lines = $this->cart($user, $promotion['type'], $promotion['config']);
        $result = $this->engine->evaluate(array_map(fn (array $l): array => array_diff_key($l, ['cost' => true, 'name' => true]), $lines), [[
            'id' => 0, 'name' => $promotion['name'] ?? 'Simulação', 'type' => $promotion['type'], 'priority' => 0, 'stackable' => false, 'config' => $promotion['config'],
        ]]);

        $gross = Money::sum(array_map(fn (array $l): Money => Money::of($l['unit_price'])->times($l['quantity']), $lines));
        $cost = Money::sum(array_map(fn (array $l): Money => Money::of($l['cost'])->times($l['quantity']), $lines));
        $discount = Money::of($result['total_promotion_discount']);
        $sale = $gross->minus($discount);
        $margin = $sale->minus($cost);

        return [
            'cost_total' => $cost->toString(),
            'sale_total' => $sale->toString(),
            'discount_total' => $discount->toString(),
            'contribution_margin' => $margin->toString(),
            'margin_percent' => $sale->isPositive() ? (string) $margin->toBigDecimal()->multipliedBy(100)->dividedBy($sale->toBigDecimal(), 2, RoundingMode::HalfUp) : null,
            'alert' => $margin->isNegative() ? 'A promoção dá prejuízo: a venda com desconto não cobre o custo.' : null,
            'items' => array_map(fn (array $l): array => ['sellable_type' => $l['sellable_type'], 'sellable_id' => $l['sellable_id'], 'name' => $l['name'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'unit_cost' => $l['cost']], $lines),
        ];
    }

    /** @param  array<string, mixed>  $config */
    public function assertScope(User $user, string $type, array $config): void
    {
        $fail = fn (string $field) => throw ValidationException::withMessages([$field => 'Item, grupo ou marca não encontrado nesta organização.']);
        $exists = fn (string $model, int $id): bool => $this->scope->scopeQuery($model::query(), $user)->whereKey($id)->exists();
        $modelOf = ['product' => Product::class, 'service' => Service::class, 'group' => ProductGroup::class, 'brand' => Brand::class];

        match ($type) {
            'buy_x_pay_y' => collect($config['targets'])->each(fn (array $t, int $i) => $exists($modelOf[$t['type']], (int) $t['id']) || $fail("config.targets.{$i}.id")),
            'bundle' => collect($config['items'])->each(fn (array $t, int $i) => $exists($modelOf[$t['sellable_type']], (int) $t['sellable_id']) || $fail("config.items.{$i}.sellable_id")),
            'category_discount' => $exists(ProductGroup::class, (int) $config['group_id']) || $fail('config.group_id'),
            'brand_discount' => $exists(Brand::class, (int) $config['brand_id']) || $fail('config.brand_id'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    private function cart(User $user, string $type, array $config): array
    {
        $id = 0;
        $line = function ($item, int $quantity) use (&$id): array {
            $isProduct = $item instanceof Product;

            return [
                'id' => ++$id, 'sellable_type' => $isProduct ? 'product' : 'service', 'sellable_id' => $item->id, 'name' => $item->name,
                'group_id' => $item->product_group_id, 'brand_id' => $isProduct ? $item->brand_id : null,
                'unit_price' => Money::of($item->price ?? '0')->toString(), 'quantity' => (string) $quantity,
                'cost' => Money::of($isProduct ? ($item->average_cost ?? '0') : $item->unitCost())->toString(),
            ];
        };

        return match ($type) {
            'buy_x_pay_y' => collect($config['targets'])->map(fn (array $t): ?object => match ($t['type']) {
                'product' => $this->products($user)->find($t['id']),
                'service' => $this->services($user)->find($t['id']),
                'group' => $this->worstMargin($this->products($user)->where('product_group_id', $t['id'])->get()->concat($this->services($user)->where('product_group_id', $t['id'])->get())),
                'brand' => $this->worstMargin($this->products($user)->where('brand_id', $t['id'])->get()),
            })->filter()->map(fn ($item): array => $line($item, (int) $config['buy']))->values()->all(),
            'bundle' => collect($config['items'])->map(fn (array $i): ?array => ($item = ($i['sellable_type'] === 'product' ? $this->products($user) : $this->services($user))->find($i['sellable_id'])) === null ? null : $line($item, (int) $i['quantity']))->filter()->values()->all(),
            'category_discount' => $this->products($user)->where('product_group_id', $config['group_id'])->get()->concat($this->services($user)->where('product_group_id', $config['group_id'])->get())->map(fn ($item): array => $line($item, 1))->values()->all(),
            'brand_discount' => $this->products($user)->where('brand_id', $config['brand_id'])->get()->map(fn ($item): array => $line($item, 1))->values()->all(),
            default => [],
        };
    }

    private function worstMargin(Collection $items): ?object
    {
        $margin = fn ($item): Money => Money::of($item->price ?? '0')->minus(Money::of($item instanceof Product ? ($item->average_cost ?? '0') : $item->unitCost()));

        return $items->sort(fn ($a, $b): int => $margin($a)->compareTo($margin($b)))->first();
    }

    private function products(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return $this->scope->scopeQuery(Product::query(), $user)->where('is_active', true);
    }

    private function services(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return $this->scope->scopeQuery(Service::query(), $user)->where('active', true);
    }
}
