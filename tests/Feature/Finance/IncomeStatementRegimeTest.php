<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * DRE por regime — critério de aceite do docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md:
 * "lançamento com `accrual_date` em agosto e `paid_at` em setembro aparece em agosto no regime
 * de competência e em setembro no de caixa".
 */
class IncomeStatementRegimeTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_the_same_entry_appears_in_different_months_depending_on_the_regime(): void
    {
        $category = FinancialCategory::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Serviços', 'nature' => 'revenue', 'kind' => 'entry', 'active' => true,
        ]);

        FinancialEntry::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'financial_category_id' => $category->id, 'description' => 'Consulta de agosto',
            'nature' => 'revenue', 'due_date' => '2026-08-15', 'accrual_date' => '2026-08-15',
            'amount' => 200, 'net_amount' => 200, 'paid_at' => '2026-09-10 10:00:00', 'paid_amount' => 200,
            'status' => 'paid', 'created_by' => $this->owner->id,
        ]);

        $accrual = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/income-statement?regime=accrual&from=2026-08-01&to=2026-09-30')
            ->assertOk()->json('data');

        $cash = $this->getJson('/api/professional/reports/income-statement?regime=cash&from=2026-08-01&to=2026-09-30')
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(200.0, $accrual['totals']['revenue']['2026-08'], 0.01);
        $this->assertEqualsWithDelta(0.0, $accrual['totals']['revenue']['2026-09'], 0.01);

        $this->assertEqualsWithDelta(0.0, $cash['totals']['revenue']['2026-08'], 0.01);
        $this->assertEqualsWithDelta(200.0, $cash['totals']['revenue']['2026-09'], 0.01);
    }

    public function test_cash_regime_ignores_entries_not_yet_settled(): void
    {
        $category = FinancialCategory::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Serviços em aberto', 'nature' => 'revenue', 'kind' => 'entry', 'active' => true,
        ]);

        FinancialEntry::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'financial_category_id' => $category->id, 'description' => 'Consulta não paga',
            'nature' => 'revenue', 'due_date' => '2026-08-20', 'accrual_date' => '2026-08-20',
            'amount' => 150, 'net_amount' => 150, 'status' => 'open', 'created_by' => $this->owner->id,
        ]);

        $cash = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/income-statement?regime=cash&from=2026-08-01&to=2026-08-31')
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(0.0, $cash['totals']['revenue']['2026-08'], 0.01);
    }

    public function test_receptionist_cannot_view_the_income_statement(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/reports/income-statement?from=2026-08-01&to=2026-08-31')
            ->assertForbidden();
    }
}
