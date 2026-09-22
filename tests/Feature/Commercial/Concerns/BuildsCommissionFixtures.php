<?php

namespace Tests\Feature\Commercial\Concerns;

use App\Models\CommissionRule;
use App\Models\Sale;

/**
 * Cenário de comissionamento interno do doc gap-simplesvet/09, montado sobre a mesma
 * clínica/equipe de `BuildsCounterFixtures`.
 */
trait BuildsCommissionFixtures
{
    /** @param  array<string, mixed>  $overrides */
    protected function makeCommissionRule(array $overrides = []): CommissionRule
    {
        return CommissionRule::create($overrides + [
            'organization_id' => $this->clinic->id,
            'scope' => 'all',
            'percent' => 5,
            'calculation_base' => 'gross',
            'only_when_received' => true,
            'active' => true,
        ]);
    }

    /**
     * Cria UMA venda com os itens dados (cada um já com `sellable_id`/`staff_id`/etc.) e paga
     * o total inteiro numa tacada — devolve a venda com os itens carregados. Várias linhas na
     * MESMA venda é como o critério de aceite "item vendido por A e outro por B" é montado.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function paySale(array $items): Sale
    {
        $this->openRegisterFor($this->receptionist);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])
            ->assertCreated()
            ->json('data.id');

        $total = 0.0;

        foreach ($items as $itemAttributes) {
            $total = $this->postJson("/api/professional/sales/{$saleId}/items", $itemAttributes)
                ->assertCreated()
                ->json('sale.total');
        }

        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $this->postJson("/api/professional/sales/{$saleId}/receipts", [
            'payment_method_id' => $cash, 'amount' => (float) $total,
        ])->assertCreated();

        return $this->reloadSaleWithInverseLoaded($saleId);
    }

    /**
     * `Model::preventLazyLoading()` está ligado fora de produção (`PerformanceServiceProvider`)
     * — `CommissionRuleResolver`/`CommissionCalculator` acessam `$item->sale` e
     * `$item->sellable`, e o `hasMany` de `Sale::items()` não hidrata o lado inverso sozinho.
     * Isto simula o mesmo `with(['sellable', 'sale.items', 'sale.receipts'])` que
     * `CommissionSettlementService` já usa em produção.
     */
    protected function reloadSaleWithInverseLoaded(int $saleId): Sale
    {
        $sale = Sale::with(['items.sellable', 'receipts'])->findOrFail($saleId);
        $sale->items->each(fn ($item) => $item->setRelation('sale', $sale));

        return $sale;
    }
}
