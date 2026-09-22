<?php

namespace Tests\Feature\Commercial;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ProfessionalClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Isolamento multi-clínica da conta corrente — critério de aceite do
 * docs/gap-simplesvet/specs/11-conta-corrente-do-cliente-spec.md
 * (`MultiCompanyBalanceIsolationTest`): o mesmo tutor tem saldo independente em cada clínica.
 */
class ClientAccountIsolationTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_the_same_client_has_an_independent_balance_in_each_clinic(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", ['type' => 'advance_credit', 'amount' => 300])
            ->assertCreated();

        $otherClinic = Organization::factory()->petshop()->create();
        $otherOwner = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($otherClinic, 'organization')->create(['user_id' => $otherOwner->id]);
        ProfessionalClient::create(['professional_id' => $otherOwner->id, 'client_id' => $this->tutor->id]);

        $balanceHere = $this->getJson("/api/professional/clients/{$this->tutor->id}/account-statement")->json('balance');

        $balanceThere = $this->actingAs($otherOwner, 'sanctum')
            ->getJson("/api/professional/clients/{$this->tutor->id}/account-statement")
            ->assertOk()
            ->json('balance');

        $this->assertEqualsWithDelta(300.0, $balanceHere, 0.01);
        $this->assertEqualsWithDelta(0.0, $balanceThere, 0.01);
    }

    public function test_the_client_balances_report_matches_the_dashboard_kpi(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", ['type' => 'adjustment_debit', 'amount' => 250])
            ->assertCreated();

        $report = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/client-balances?situation=debtor')
            ->assertOk();

        $dashboard = $this->getJson('/api/professional/dashboard/receivables-from-clients')->assertOk();

        $reportTotal = collect($report->json('data'))->sum(fn (array $row): float => abs($row['balance']));
        $this->assertEqualsWithDelta(250.0, $reportTotal, 0.01);
        $this->assertEqualsWithDelta(250.0, $dashboard->json('data.receivable_from_clients'), 0.01);
        $this->assertSame(1, $dashboard->json('data.debtor_count'));
    }

    public function test_only_the_owner_sees_the_consolidated_report_but_any_active_member_sees_one_clients_statement(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/reports/client-balances')
            ->assertForbidden();

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson("/api/professional/clients/{$this->tutor->id}/account-statement")
            ->assertOk();

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", ['type' => 'advance_credit', 'amount' => 100])
            ->assertForbidden();
    }
}
