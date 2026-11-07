<?php

namespace Tests\Feature\Finance;

use App\Models\CostCenter;
use App\Models\FinancialCategory;
use App\Support\Money\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Despesa variável na DRE gerencial (pedido do usuário sobre a 1.7): a categoria
 * `variable_expense` vira a linha "Despesas variáveis", reduz a MC e sai das despesas fixas do
 * ponto de equilíbrio; o lucro líquido não muda. Vale também na DRE por centro (2.4) e no widget
 * de ponto de equilíbrio (3.6).
 */
class VariableExpenseTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private FinancialCategory $bags;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-20 15:00:00');

        $this->openRegisterFor($this->owner);
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $this->makeProduct(['price' => 200, 'average_cost' => '140.0000', 'controls_stock' => false])->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => 200])->assertCreated();

        $rent = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
        $this->bags = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Sacolas', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
        $store = CostCenter::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Loja', 'type' => 'store']);

        $this->expense($rent, '30.00');
        $this->expense($this->bags, '12.00', [['cost_center_id' => $store->id, 'percent' => 100]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_variable_category_lowers_margin_and_break_even_and_keeps_net_income(): void
    {
        $asFixed = $this->statement();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/financial-categories/{$this->bags->id}", ['dre_treatment' => 'variable_expense'])
            ->assertOk()->assertJsonPath('data.dre_treatment', 'variable_expense')->assertJsonPath('data.dre_treatment_label', 'Despesa variável (reduz a margem)');
        $asVariable = $this->statement();
        $fixed = $this->lines($asFixed);
        $variable = $this->lines($asVariable);

        // Venda 200 − CMV 140 = MC 60 (fixa) → 48 com as sacolas; fixas 42 → 30.
        $this->assertSame(['0.00', '12.00'], [$fixed['variable_expenses'], $variable['variable_expenses']]);
        $this->assertSame(['60.00', '48.00'], [$fixed['contribution_margin'], $variable['contribution_margin']]);
        $this->assertSame(['42.00', '30.00'], [$fixed['operating_expenses'], $variable['operating_expenses']]);
        $this->assertSame($fixed['net_income'], $variable['net_income']);
        $this->assertSame(['30.00', '24.00'], [$asFixed['margin_percent'], $asVariable['margin_percent']]);
        // Ponto de equilíbrio: 42 × 200 / 60 = 140,00 → 30 × 200 / 48 = 125,00.
        $this->assertSame(['140.00', '125.00'], [$asFixed['break_even']['amount'], $asVariable['break_even']['amount']]);

        $keys = collect($asVariable['lines'])->pluck('key')->all();
        $this->assertSame(array_search('commissions', $keys) + 1, array_search('variable_expenses', $keys));
        $this->assertSame(array_search('variable_expenses', $keys) + 1, array_search('contribution_margin', $keys));

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/dashboard-widgets/data/break_even')->assertOk()->assertJsonPath('data.values.value', '125.00');
    }

    public function test_by_cost_center_the_variable_expense_follows_the_allocation_and_sums_to_the_total(): void
    {
        $this->bags->update(['dre_treatment' => 'variable_expense']);
        $data = $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/reports/income-statement?regime=managerial&from=2026-10-01&to=2026-10-31&breakdown=cost_center')->assertOk()->json('data');
        $total = $this->lines($data);
        $centers = collect($data['cost_centers'])->keyBy(fn (array $c): string => $c['cost_center']['name']);

        $this->assertSame('12.00', collect($centers['Loja']['lines'])->firstWhere('key', 'variable_expenses')['total']);
        foreach ($total as $key => $value) {
            $this->assertSame($value, Money::sum($centers->map(fn (array $c): string => collect($c['lines'])->firstWhere('key', $key)['total'])->all())->toString(), "linha {$key}");
        }
    }

    public function test_variable_treatment_only_applies_to_expense_categories(): void
    {
        $revenue = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Outras', 'nature' => 'revenue', 'kind' => 'entry', 'active' => true]);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/financial-categories/{$revenue->id}", ['dre_treatment' => 'variable_expense'])
            ->assertUnprocessable()->assertJsonValidationErrors('dre_treatment');
        $this->postJson('/api/professional/financial-categories', ['name' => 'Combustível', 'nature' => 'expense', 'dre_treatment' => 'variable_expense'])
            ->assertCreated()->assertJsonPath('data.dre_treatment', 'variable_expense');
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/reports/income-statement?regime=managerial&from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
    }

    /** @param  array<string, mixed>  $statement
     * @return array<string, string> */
    private function lines(array $statement): array
    {
        return (new Collection($statement['lines']))->pluck('total', 'key')->all();
    }

    /** @param  list<array<string, mixed>>|null  $allocations */
    private function expense(FinancialCategory $category, string $amount, ?array $allocations = null): void
    {
        $body = ['financial_category_id' => $category->id, 'description' => $category->name, 'nature' => 'expense', 'due_date' => '2026-10-10', 'amount' => $amount];
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/financial-entries', $body + ($allocations === null ? [] : ['allocations' => $allocations]))->assertCreated();
    }
}
