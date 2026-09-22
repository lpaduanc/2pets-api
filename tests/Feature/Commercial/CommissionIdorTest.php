<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCommissionFixtures;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md,
 * regra de negócio 5: "funcionário só vê a própria comissão; dono/gerente vê todas". Vazamento
 * de comissão de colega é passivo trabalhista, não só bug técnico.
 */
class CommissionIdorTest extends TestCase
{
    use BuildsCommissionFixtures;
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_me_commissions_never_returns_another_staff_members_settlement(): void
    {
        $this->makeCommissionRule(['percent' => 10]);
        $product = $this->makeProduct(['price' => 100]);
        $this->paySale([['sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id]]);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', [
            'staff_id' => $this->groomerMember->id, 'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->toDateString(), 'received_until' => now()->toDateString(),
        ])->assertCreated();

        // A recepcionista (outro staff da MESMA clínica) não vê a comissão da groomer.
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/me/commissions')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A própria groomer vê a própria.
        $this->actingAs($this->groomer, 'sanctum')
            ->getJson('/api/professional/me/commissions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_non_owner_staff_member_cannot_open_another_staffs_settlement_by_id(): void
    {
        $this->makeCommissionRule(['percent' => 10]);
        $product = $this->makeProduct(['price' => 100]);
        $this->paySale([['sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id]]);

        $settlementId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', [
            'staff_id' => $this->groomerMember->id, 'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->toDateString(), 'received_until' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson("/api/professional/commission-settlements/{$settlementId}")
            ->assertStatus(403);
    }

    public function test_a_non_owner_staff_member_cannot_list_all_settlements_of_the_clinic(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/commission-settlements')
            ->assertStatus(403);
    }
}
