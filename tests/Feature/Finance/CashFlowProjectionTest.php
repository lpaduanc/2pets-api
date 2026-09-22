<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Fluxo de caixa projetado — critério de aceite do
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md: "projeta meses futuros a partir de
 * `due_date` de lançamentos em aberto, sem depender de job/scheduler" (tudo calculado na query,
 * no momento da chamada — não há nenhum `queue:work`/`schedule:work` rodando no ambiente).
 */
class CashFlowProjectionTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_open_entries_project_into_the_month_of_their_due_date(): void
    {
        FinancialAccount::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Banco', 'type' => 'checking', 'opening_balance' => 1000,
            'opening_balance_date' => now()->subYear()->toDateString(),
        ]);

        $revenueCategory = FinancialCategory::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Receita futura', 'nature' => 'revenue', 'kind' => 'entry', 'active' => true,
        ]);
        $expenseCategory = FinancialCategory::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Despesa futura', 'nature' => 'expense', 'kind' => 'entry', 'active' => true,
        ]);

        $nextMonth = Carbon::today()->addMonthNoOverflow()->format('Y-m');

        FinancialEntry::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'financial_category_id' => $revenueCategory->id, 'description' => 'A receber',
            'nature' => 'revenue', 'due_date' => Carbon::today()->addMonthNoOverflow()->toDateString(),
            'accrual_date' => Carbon::today()->toDateString(), 'amount' => 500, 'net_amount' => 500,
            'status' => 'open', 'created_by' => $this->owner->id,
        ]);
        FinancialEntry::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'financial_category_id' => $expenseCategory->id, 'description' => 'A pagar',
            'nature' => 'expense', 'due_date' => Carbon::today()->addMonthNoOverflow()->toDateString(),
            'accrual_date' => Carbon::today()->toDateString(), 'amount' => 200, 'net_amount' => 200,
            'status' => 'open', 'created_by' => $this->owner->id,
        ]);

        $data = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/cash-flow?months=3')
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(1000.0, $data['opening_balance'], 0.01);

        $bucket = collect($data['months'])->firstWhere('month', $nextMonth);
        $this->assertNotNull($bucket);
        $this->assertEqualsWithDelta(500.0, $bucket['expected_revenue'], 0.01);
        $this->assertEqualsWithDelta(200.0, $bucket['expected_expense'], 0.01);
        $this->assertEqualsWithDelta(1300.0, $bucket['projected_balance'], 0.01);
    }

    public function test_receptionist_cannot_view_the_cash_flow_projection(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/reports/cash-flow')
            ->assertForbidden();
    }
}
