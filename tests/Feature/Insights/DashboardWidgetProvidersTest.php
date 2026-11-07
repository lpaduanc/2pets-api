<?php

namespace Tests\Feature\Insights;

use App\Models\Appointment;
use App\Models\CostCenter;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ProductBatch;
use App\Models\ProductGroup;
use App\Models\SalesGoal;
use App\Models\ServicePackage;
use App\Models\StockMovement;
use App\Models\StockValuationSnapshot;
use App\Models\User;
use App\Services\Insights\Widgets\DashboardWidgetRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Widgets do painel com provider (PLANO 3.6): catálogo por permissão, dados de cada provider
 * com dado mínimo, 403 nos de custo/lucro sem `reports.profitability`, isolamento entre
 * organizações, o widget salvo no layout e a foto diária do estoque (idempotente).
 */
class DashboardWidgetProvidersTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-20 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_catalog_follows_permissions_and_profit_widgets_are_forbidden_without_profitability(): void
    {
        $ownerKeys = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/dashboard-widgets/catalog')->assertOk()->json('data'))->pluck('key');
        $this->assertEqualsCanonicalizing(app(DashboardWidgetRegistry::class)->keys(), $ownerKeys->all());

        $deskKeys = collect($this->actingAs($this->receptionist, 'sanctum')->getJson('/api/professional/dashboard-widgets/catalog')->assertOk()->json('data'))->pluck('key');
        foreach (['stock_valuation', 'gross_profit', 'net_income', 'break_even', 'cost_center_ranking'] as $key) {
            $this->assertNotContains($key, $deskKeys->all());
            $this->widget($key, user: $this->receptionist)->assertForbidden();
        }

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/dashboard-widgets/data/nope')->assertNotFound();
        $this->widget('expiring_batches', '?days=0')->assertUnprocessable()->assertJsonValidationErrors('days');
    }

    public function test_stock_widgets(): void
    {
        $food = $this->makeProduct(['name' => 'Ração', 'price' => 12, 'average_cost' => '5.5000', 'stock_quantity' => 10, 'min_stock' => 2]);
        $gloves = $this->makeProduct(['name' => 'Luva', 'price' => 0, 'average_cost' => '1.0000', 'stock_quantity' => 1, 'min_stock' => 5, 'is_supply' => true]);
        $food->forceFill(['stock_quantity' => 10])->save();
        $gloves->forceFill(['stock_quantity' => 1])->save();
        StockValuationSnapshot::create(['organization_id' => $this->clinic->id, 'snapshot_date' => '2026-09-30', 'cost_value' => '50.00', 'sale_value' => '0.00', 'product_count' => 1]);

        $this->widget('stock_valuation')->assertOk()
            ->assertJsonPath('data.values.cost_value', '56.00')
            ->assertJsonPath('data.values.sale_value', '120.00')
            ->assertJsonPath('data.values.cost_variation_percent', '12.00')
            ->assertJsonPath('data.values.sale_variation_percent', null);

        $this->widget('low_stock')->assertOk()->assertJsonPath('data.values.count', 1)->assertJsonPath('data.values.items.0.product_name', 'Luva');

        ProductBatch::create(['product_id' => $food->id, 'batch_code' => 'L1', 'expires_at' => '2026-10-30', 'quantity' => 3]);
        ProductBatch::create(['product_id' => $food->id, 'batch_code' => 'L2', 'expires_at' => '2026-12-15', 'quantity' => 3]);
        ProductBatch::create(['product_id' => $food->id, 'batch_code' => 'L0', 'expires_at' => '2026-10-25', 'quantity' => 0]);
        $this->widget('expiring_batches')->assertOk()->assertJsonPath('data.values.count', 1)->assertJsonPath('data.values.items.0.days_left', 10);
        $this->widget('expiring_batches', '?days=60')->assertOk()->assertJsonPath('data.values.count', 2);

        foreach ([['Luva', 12, '2026-10-10'], ['Luva', 3, '2026-09-01']] as [$name, $quantity, $date]) {
            StockMovement::create(['organization_id' => $this->clinic->id, 'product_id' => $gloves->id, 'type' => 'internal_use', 'direction' => 'out',
                'quantity' => $quantity, 'balance_after' => 0, 'occurred_at' => $date]);
        }
        $this->widget('top_supplies')->assertOk()->assertJsonPath('data.values.items', [['product_id' => $gloves->id, 'product_name' => 'Luva', 'unit' => $gloves->fresh()->unit_of_sale, 'quantity' => '12.0000']]);
    }

    public function test_sales_profit_cost_center_and_goal_widgets_reuse_the_managerial_numbers(): void
    {
        $store = CostCenter::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Loja', 'type' => 'store']);
        $group = ProductGroup::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Rações', 'active' => true, 'cost_center_id' => $store->id]);
        $product = $this->makeProduct(['price' => 200, 'average_cost' => '140.0000', 'controls_stock' => false, 'product_group_id' => $group->id]);
        $nail = $this->makeService(['name' => 'Unha', 'price' => 20, 'commission_percent' => 0]);
        $this->sell([['product', $product->id], ['service', $nail->id]]);
        $rent = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
        $this->expense($rent, '30.00', '2026-10-10');
        SalesGoal::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'month' => '2026-10', 'scope' => 'total', 'amount' => '1000.00']);

        $this->widget('sales_by_category')->assertOk()
            ->assertJsonPath('data.values.products.groups.0', ['group_id' => $group->id, 'group_name' => 'Rações', 'net_revenue' => '200.00'])
            ->assertJsonPath('data.values.services.groups.0.group_name', 'Sem grupo')
            ->assertJsonPath('data.values.services.total', '20.00');

        // Receita 220 − CMV 140 = 80; lucro líquido 80 − 30 de aluguel = 50; setembro vazio → variação null.
        $this->widget('gross_profit')->assertOk()->assertJsonPath('data.values.value', '80.00')->assertJsonPath('data.values.previous_value', '0.00')->assertJsonPath('data.values.variation_percent', null);
        $this->widget('net_income')->assertOk()->assertJsonPath('data.values.value', '50.00');
        // Margem da própria linha sobre a receita líquida (220,00): 80/220 = 36,36%; 50/220 = 22,73%.
        $this->widget('gross_profit')->assertJsonPath('data.values.margin_percent', '36.36');
        $this->widget('net_income')->assertJsonPath('data.values.margin_percent', '22.73');
        // MC 80 / 220 = 36,36%; fixas 30 → 82,50 (BreakEvenCalculator); 220 ≥ 82,50.
        $this->widget('break_even')->assertOk()->assertJsonPath('data.values.reached', true)->assertJsonPath('data.values.sales_revenue', '220.00')
            ->assertJsonPath('data.values.contribution_margin_percent', '36.36')->assertJsonMissingPath('data.values.margin_percent');

        $this->widget('cost_center_ranking')->assertOk()
            ->assertJsonPath('data.values.items.0.name', 'Loja')
            ->assertJsonPath('data.values.items.0.net_income', '60.00')
            ->assertJsonPath('data.values.unallocated_net_income', '-10.00');

        $this->widget('sales_goals')->assertOk()->assertJsonPath('data.values.goals.0.actual', '220.00')->assertJsonPath('data.values.goals.0.achieved_percent', '22.00');
    }

    public function test_profit_margins_are_negative_with_a_loss_and_null_without_revenue(): void
    {
        $this->widget('net_income')->assertOk()->assertJsonPath('data.values.margin_percent', null);
        $this->widget('gross_profit')->assertOk()->assertJsonPath('data.values.margin_percent', null);
        $this->widget('break_even')->assertOk()->assertJsonPath('data.values.contribution_margin_percent', null);

        $this->sell([['service', $this->makeService(['name' => 'Unha', 'price' => 20, 'commission_percent' => 0])->id]]);
        $rent = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
        $this->expense($rent, '30.00', '2026-10-10');

        // Receita 20, lucro líquido 20 − 30 = −10 → −50,00%; o lucro bruto segue 100,00%.
        $this->widget('net_income')->assertOk()->assertJsonPath('data.values.value', '-10.00')->assertJsonPath('data.values.margin_percent', '-50.00');
        $this->widget('gross_profit')->assertOk()->assertJsonPath('data.values.margin_percent', '100.00');
    }

    public function test_agenda_clients_finance_and_package_widgets(): void
    {
        foreach ([['2026-10-05', 'completed'], ['2026-10-06', 'no_show'], ['2026-10-07', 'cancelled'], ['2026-08-10', 'completed']] as [$date, $status]) {
            Appointment::create(['professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
                'appointment_date' => $date, 'appointment_time' => '09:00', 'type' => 'grooming', 'status' => $status]);
        }
        $months = collect($this->widget('appointments_scheduled_completed')->assertOk()->json('data.values.months'))->keyBy('month');
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], $months->keys()->all());
        $this->assertSame(['month' => '2026-10', 'scheduled' => 2, 'completed' => 1, 'completion_percent' => '50.00'], $months['2026-10']);
        $this->assertNull($months['2026-09']['completion_percent']);

        $this->widget('active_clients', '?goal=4')->assertOk()->assertJsonPath('data.values.active', 1)->assertJsonPath('data.values.goal_percent', '25.00');

        $rent = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
        $this->expense($rent, '100.00', '2026-10-18');
        $this->expense($rent, '40.00', '2026-10-25');
        $this->expense($rent, '999.00', '2026-11-30');
        $this->widget('payables_due')->assertOk()
            ->assertJsonPath('data.values.count', 2)->assertJsonPath('data.values.total', '140.00')
            ->assertJsonPath('data.values.overdue_count', 1)->assertJsonPath('data.values.overdue_total', '100.00');

        $package = ServicePackage::create(['professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'name' => '2 banhos', 'price' => 100, 'validity_type' => 'days_from_sale', 'validity_days' => 20, 'active' => true]);
        $package->items()->create(['service_id' => $this->makeService(['name' => 'Banho', 'price' => 60])->id, 'quantity' => 2]);
        $this->openRegisterFor($this->owner);
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'package', 'sellable_id' => $package->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => 100])->assertCreated();
        $this->widget('packages_expiring')->assertOk()->assertJsonPath('data.values.count', 1)->assertJsonPath('data.values.deferred_total', '100.00')->assertJsonPath('data.values.items.0.days_left', 20);
    }

    public function test_other_organization_sees_only_its_own_data_and_saved_widgets_carry_availability(): void
    {
        $this->makeProduct(['name' => 'Luva', 'stock_quantity' => 0, 'min_stock' => 5])->forceFill(['stock_quantity' => 1])->save();
        $other = Organization::factory()->petshop()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);
        $this->widget('low_stock', user: $stranger)->assertOk()->assertJsonPath('data.values.count', 0);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/dashboard-widgets', ['indicator_key' => 'expiring_batches', 'config' => ['days' => 15]])
            ->assertCreated()->assertJsonPath('data.config', ['days' => 15]);
        $this->postJson('/api/professional/dashboard-widgets', ['indicator_key' => 'low_stock'])->assertCreated()->assertJsonPath('data.config', []);
        $this->postJson('/api/professional/dashboard-widgets', ['indicator_key' => 'expiring_batches', 'config' => ['days' => 999]])->assertUnprocessable();
        $this->getJson('/api/professional/dashboard-widgets')->assertOk()->assertJsonPath('data.0.kind', 'provider')->assertJsonPath('data.0.available', true);

        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/dashboard-widgets', ['indicator_key' => 'gross_profit'])->assertForbidden();
    }

    public function test_stock_snapshot_command_is_idempotent_per_day(): void
    {
        $product = $this->makeProduct(['price' => 10, 'average_cost' => '4.0000']);
        $product->forceFill(['stock_quantity' => 3])->save();

        $this->artisan('stock:snapshot-valuation', ['--date' => '2026-10-31'])->assertSuccessful();
        $product->forceFill(['stock_quantity' => 5])->save();
        $this->artisan('stock:snapshot-valuation', ['--date' => '2026-10-31'])->assertSuccessful();

        $snapshot = StockValuationSnapshot::sole();
        $this->assertSame(['2026-10-31', '20.00', '50.00', 1], [$snapshot->snapshot_date->toDateString(), (string) $snapshot->cost_value, (string) $snapshot->sale_value, $snapshot->product_count]);
    }

    private function widget(string $key, string $query = '', ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum')->getJson("/api/professional/dashboard-widgets/data/{$key}{$query}");
    }

    /** @param  list<array{0: string, 1: int}>  $items */
    private function sell(array $items): void
    {
        $this->openRegisterFor($this->owner);
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');

        foreach ($items as [$type, $id]) {
            $total = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id])->assertCreated()->json('sale.total');
        }

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => (float) $total])->assertCreated();
    }

    private function expense(FinancialCategory $category, string $amount, string $due): void
    {
        FinancialEntry::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'financial_category_id' => $category->id,
            'description' => 'Despesa', 'nature' => 'expense', 'due_date' => $due, 'accrual_date' => '2026-10-10', 'amount' => $amount, 'net_amount' => $amount,
            'status' => 'open', 'created_by' => $this->owner->id]);
    }
}
