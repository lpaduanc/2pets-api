<?php

namespace Tests\Feature\Commercial;

use App\Models\PartnerPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md
 * — repasse a parceiro terceiro (regra de negócio 7): registro e conciliação manuais.
 */
class PartnerPayoutReconciliationTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_the_owner_registers_a_manual_payout_to_a_partner(): void
    {
        $partner = User::factory()->professional()->create();

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/partner-payouts', [
            'partner_user_id' => $partner->id,
            'description' => 'Plantão de fim de semana',
            'amount' => 350.00,
        ])->assertCreated();

        $this->assertSame('pending', $response->json('data.status'));

        $this->assertDatabaseHas('partner_payouts', [
            'id' => $response->json('data.id'), 'partner_user_id' => $partner->id, 'status' => 'pending',
        ]);
    }

    public function test_reconciling_marks_it_reconciled_and_cannot_be_reconciled_twice(): void
    {
        $partner = User::factory()->professional()->create();
        $payout = PartnerPayout::create([
            'organization_id' => $this->clinic->id,
            'partner_user_id' => $partner->id,
            'description' => 'Diária de sábado',
            'amount' => 200,
            'status' => 'pending',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/partner-payouts/{$payout->id}/reconcile", ['payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');

        $payout->refresh();
        $this->assertNotNull($payout->reconciled_at);
        $this->assertSame($this->owner->id, $payout->reconciled_by);

        $this->postJson("/api/professional/partner-payouts/{$payout->id}/reconcile", ['payment_method' => 'pix'])
            ->assertStatus(422);
    }

    public function test_a_non_owner_staff_member_cannot_register_a_payout(): void
    {
        $partner = User::factory()->professional()->create();

        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/partner-payouts', [
            'partner_user_id' => $partner->id, 'description' => 'Teste', 'amount' => 100,
        ])->assertStatus(403);
    }

    /**
     * Achado desta auditoria: `index` não tinha NENHUMA autorização antes desta rodada — um
     * membro sem vínculo de dono conseguia listar quanto a clínica paga a parceiros terceiros
     * (informação financeira sensível), a mesma régua que já protegia `store`/`reconcile`.
     */
    public function test_a_non_owner_staff_member_cannot_list_partner_payouts(): void
    {
        $partner = User::factory()->professional()->create();
        PartnerPayout::create([
            'organization_id' => $this->clinic->id,
            'partner_user_id' => $partner->id,
            'description' => 'Diária de sábado',
            'amount' => 200,
            'status' => 'pending',
        ]);

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/partner-payouts')
            ->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/partner-payouts')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
