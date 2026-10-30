<?php

namespace App\Services\Commercial\ServiceCost;

use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceSupply;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Ficha técnica de insumos do serviço — docs/gap-petflow/PLANO.md, 1.3. Lê, substitui e
 * pré-visualiza a ficha; a conta é sempre a do `ServiceCostCalculator`.
 *
 * O produto de cada linha já chega validado no escopo de quem chama (`ScopedProductExists`); a
 * prévia ainda assim busca os custos pela query ESCOPADA, para nunca devolver o custo médio de um
 * produto de outra clínica.
 */
final class ServiceSupplyService
{
    public function __construct(
        private readonly ServiceCostCalculator $calculator,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * Formato do `GET services/{id}/supplies`.
     *
     * @return array<string, mixed>
     */
    public function breakdown(Service $service): array
    {
        $service->load(['supplies' => fn ($query) => $query->orderBy('id')->with('product')]);
        $cost = $this->calculator->calculate($service->price, $service->direct_cost, $service->costLines());

        return [
            'direct_cost' => $cost['direct_cost'],
            'items' => $service->supplies->values()
                ->map(fn (ServiceSupply $supply, int $index): array => self::itemPayload($supply, $cost['items'][$index]))
                ->all(),
            ...Arr::except($cost, ['items', 'direct_cost']),
        ];
    }

    /**
     * Substitui a ficha inteira (sync) e o custo direto. `direct_cost` ausente mantém o atual.
     *
     * @param  array{direct_cost?: string|int|float|null, items: list<array{product_id: int, quantity: string|int|float}>}  $data
     * @return array<string, mixed>
     */
    public function replace(Service $service, array $data): array
    {
        DB::transaction(function () use ($service, $data): void {
            if (array_key_exists('direct_cost', $data)) {
                $service->update(['direct_cost' => Money::of($data['direct_cost'])->toString()]);
            }

            $service->supplies()->delete();

            foreach ($data['items'] as $item) {
                $service->supplies()->create([
                    'organization_id' => $service->organization_id,
                    'professional_id' => $service->professional_id,
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (string) Decimal::of($item['quantity']),
                ]);
            }
        });

        return $this->breakdown($service);
    }

    /**
     * Prévia de uma ficha não salva (`POST services/cost-preview`).
     *
     * @param  array{price: string|int|float, direct_cost?: string|int|float|null, items?: list<array{product_id: int, quantity: string|int|float}>|null}  $data
     * @return array<string, mixed>
     */
    public function preview(User $user, array $data): array
    {
        $items = $data['items'] ?? [];
        $costs = $this->scope->scopeQuery(Product::query(), $user)
            ->whereKey(array_column($items, 'product_id'))
            ->pluck('average_cost', 'id');

        $lines = array_map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (string) Decimal::of($item['quantity']),
            'average_cost' => $costs[(int) $item['product_id']] ?? null,
        ], $items);

        return $this->calculator->calculate($data['price'], $data['direct_cost'] ?? null, $lines);
    }

    /**
     * @param  array{product_id: int|null, unit_cost: string, line_cost: string}  $cost
     * @return array<string, mixed>
     */
    private static function itemPayload(ServiceSupply $supply, array $cost): array
    {
        return [
            'id' => $supply->id,
            'product_id' => $supply->product_id,
            'product' => [
                'id' => $supply->product?->id,
                'name' => $supply->product?->name,
                // A unidade de estoque do produto se chama `unit_of_sale` no cadastro.
                'unit' => $supply->product?->unit_of_sale,
                'average_cost' => $cost['unit_cost'],
            ],
            'quantity' => (string) $supply->quantity,
            'unit_cost' => $cost['unit_cost'],
            'line_cost' => $cost['line_cost'],
        ];
    }
}
