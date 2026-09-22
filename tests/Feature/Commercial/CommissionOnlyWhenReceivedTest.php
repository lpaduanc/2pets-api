<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCommissionFixtures;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md,
 * regra de negócio 1: comissão só entra em fechamento sobre venda EFETIVAMENTE RECEBIDA, nunca
 * sobre venda emitida — mesmo que o item tenha `commission_percent` preenchido.
 */
class CommissionOnlyWhenReceivedTest extends TestCase
{
    use BuildsCommissionFixtures;
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_an_item_from_an_open_unpaid_sale_never_appears_in_open_commissions(): void
    {
        $this->makeCommissionRule(['percent' => 10]);
        $product = $this->makeProduct(['price' => 100, 'commission_percent' => 5]);

        $this->openRegisterFor($this->receptionist);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id,
        ])->assertCreated();

        // Nenhum recebimento registrado: a venda continua `open`/`unpaid`.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/commissions/open?staff_id='.$this->groomerMember->id.'&received_until='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_partially_paid_sale_still_does_not_count_until_fully_received(): void
    {
        $this->makeCommissionRule(['percent' => 10]);
        $product = $this->makeProduct(['price' => 100]);

        $this->openRegisterFor($this->receptionist);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id,
        ])->assertCreated();

        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 40])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'unpaid');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/commissions/open?staff_id='.$this->groomerMember->id.'&received_until='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
