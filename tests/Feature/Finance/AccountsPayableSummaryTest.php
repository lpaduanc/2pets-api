<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Contas a pagar/a receber — critérios de aceite do
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md: os 5 totalizadores, "vencido" é
 * sempre derivado na query (nunca uma coluna), e só `OWNER` acessa o relatório.
 */
class AccountsPayableSummaryTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $expenseCategoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();

        $this->expenseCategoryId = FinancialCategory::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Fornecedores', 'nature' => 'expense', 'kind' => 'entry', 'active' => true,
        ])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_five_totalizers_are_mutually_exclusive_except_all(): void
    {
        // Datas de terça a sexta, longe de qualquer feriado nacional — nada aqui rola de
        // dia por causa do `FinancialInstallmentPlanner`/`DueDateService` (testados à parte).
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-19'));

        $this->createExpense(due: '2026-11-16', amount: 100); // vencida (passado, aberta)
        $this->createExpense(due: '2026-11-25', amount: 50);  // a vencer
        $paidId = $this->createExpense(due: '2026-11-17', amount: 30);
        $this->postJson("/api/professional/financial-entries/{$paidId}/settle", ['paid_amount' => 30])->assertOk();

        $summary = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/accounts-payable?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->json('summary');

        $this->assertSame(3, $summary['all']['count']);
        $this->assertEqualsWithDelta(180.0, $summary['all']['total'], 0.01);
        $this->assertSame(2, $summary['open']['count']);
        $this->assertSame(1, $summary['paid']['count']);
        $this->assertSame(1, $summary['overdue']['count']);
        $this->assertEqualsWithDelta(100.0, $summary['overdue']['total'], 0.01);
        $this->assertSame(1, $summary['due']['count']);
        $this->assertEqualsWithDelta(50.0, $summary['due']['total'], 0.01);
    }

    public function test_overdue_is_derived_from_the_date_not_a_persisted_column(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-19'));
        $this->createExpense(due: '2026-11-20', amount: 75);

        $today = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/accounts-payable?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->json('summary');

        $this->assertSame(0, $today['overdue']['count']);
        $this->assertSame(1, $today['due']['count']);

        // Sem rodar nenhum job: só o relógio avançou e a mesma linha vira vencida.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-23'));

        $later = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/accounts-payable?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->json('summary');

        $this->assertSame(1, $later['overdue']['count']);
        $this->assertSame(0, $later['due']['count']);
    }

    public function test_receivable_report_groups_received_amounts_by_payment_method(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 80]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 80])->assertCreated();

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/accounts-receivable?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk();

        $this->assertSame(1, $response->json('summary.paid.count'));
        $byMethod = $response->json('by_payment_method');
        $this->assertNotEmpty($byMethod);
        $this->assertEqualsWithDelta(80.0, collect($byMethod)->sum('amount'), 0.01);
    }

    public function test_only_the_owner_sees_the_accounts_payable_report(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/reports/accounts-payable?from=2026-10-01&to=2026-10-31')
            ->assertForbidden();
    }

    private function createExpense(string $due, float $amount): int
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/financial-entries', [
                'financial_category_id' => $this->expenseCategoryId,
                'description' => 'Despesa de teste', 'nature' => 'expense',
                'due_date' => $due, 'amount' => $amount,
            ])->assertCreated()->json('data.0.id');
    }
}
