<?php

namespace Tests\Feature\Commercial;

use App\Models\CommissionSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCommissionFixtures;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md
 * sobre o fechamento em si: granularidade por funcionário, unicidade de item e liquidação.
 */
class CommissionSettlementTest extends TestCase
{
    use BuildsCommissionFixtures;
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_two_items_sold_by_different_staff_close_into_two_separate_settlements(): void
    {
        $this->makeCommissionRule(['percent' => 10]);
        $productA = $this->makeProduct(['name' => 'A', 'price' => 100, 'sku' => 'A-'.uniqid()]);
        $productB = $this->makeProduct(['name' => 'B', 'price' => 50, 'sku' => 'B-'.uniqid()]);

        $this->paySale([
            ['sellable_type' => 'product', 'sellable_id' => $productA->id, 'staff_id' => $this->groomerMember->id],
            ['sellable_type' => 'product', 'sellable_id' => $productB->id, 'staff_id' => $this->receptionistMember->id],
        ]);

        $period = ['period_from' => now()->subDay()->toDateString(), 'period_to' => now()->toDateString(), 'received_until' => now()->toDateString()];

        $groomerSettlement = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/commission-settlements', $period + ['staff_id' => $this->groomerMember->id])
            ->assertCreated()
            // `assertJsonPath` compara com `assertSame`: um total inteiro (10.0) serializa em
            // JSON como `10` (sem casa decimal), que o `json_decode` do teste lê como `int` —
            // por isso o valor esperado aqui é `int`, não `float`, mesmo sendo dinheiro.
            ->assertJsonPath('data.total_amount', 10)
            ->assertJsonCount(1, 'data.items');

        $receptionistSettlement = $this->postJson('/api/professional/commission-settlements', $period + ['staff_id' => $this->receptionistMember->id])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 5)
            ->assertJsonCount(1, 'data.items');

        $this->assertNotSame($groomerSettlement->json('data.id'), $receptionistSettlement->json('data.id'));
    }

    public function test_closing_the_same_period_twice_finds_no_eligible_item_left(): void
    {
        $this->makeCommissionRule(['percent' => 10]);
        $product = $this->makeProduct(['price' => 100]);
        $this->paySale([['sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id]]);

        $period = ['staff_id' => $this->groomerMember->id, 'period_from' => now()->subDay()->toDateString(), 'period_to' => now()->toDateString(), 'received_until' => now()->toDateString()];

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', $period)->assertCreated();

        // Mesmo item, novo fechamento: já foi consumido pelo primeiro (unique(sale_item_id)).
        $this->postJson('/api/professional/commission-settlements', $period)->assertStatus(422);
    }

    public function test_paying_a_settlement_marks_it_paid_and_cannot_be_paid_twice(): void
    {
        $this->makeCommissionRule(['percent' => 10]);
        $product = $this->makeProduct(['price' => 100]);
        $this->paySale([['sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id]]);

        $settlementId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/commission-settlements', [
                'staff_id' => $this->groomerMember->id, 'period_from' => now()->subDay()->toDateString(),
                'period_to' => now()->toDateString(), 'received_until' => now()->toDateString(),
            ])->assertCreated()->json('data.id');

        $this->postJson("/api/professional/commission-settlements/{$settlementId}/pay", ['payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame('paid', CommissionSettlement::find($settlementId)->status->value);
        $this->assertTrue(CommissionSettlement::find($settlementId)->isImmutable());

        $this->postJson("/api/professional/commission-settlements/{$settlementId}/pay", ['payment_method' => 'pix'])
            ->assertStatus(422);
    }
}
