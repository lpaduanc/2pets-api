<?php

namespace Tests\Feature\Commercial;

use App\Models\CostCenter;
use App\Models\Holiday;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ProductGroup;
use App\Models\SalesGoal;
use App\Models\ServicePackage;
use App\Models\SoldPackage;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Metas de venda — docs/gap-petflow/PLANO.md, 2.5 (golden `sales-goal.json`): CRUD e unicidade,
 * realizado por escopo batendo com a receita líquida da DRE gerencial do mês, dias úteis com
 * feriado, isolamento entre organizações e permissão pelo cargo.
 */
class SalesGoalTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->clinic->update(['effective_sales_tax_rate' => '6', 'working_days' => [1, 2, 3, 4, 5]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_crud_and_uniqueness_by_month_scope_and_target(): void
    {
        [$store] = $this->centers('Loja');
        $id = $this->goal(['month' => '2026-10', 'scope' => 'total', 'amount' => 60000])->assertCreated()
            ->assertJsonPath('data.amount', '60000.00')->assertJsonPath('data.scope_id', null)->json('data.id');
        $this->goal(['month' => '2026-10', 'scope' => 'total', 'amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('scope');
        // Mesmo escopo em outro mês, e centro/profissional diferentes, são metas distintas.
        $this->goal(['month' => '2026-11', 'scope' => 'total', 'amount' => 1])->assertCreated();
        $this->goal(['month' => '2026-10', 'scope' => 'cost_center', 'scope_id' => $store->id, 'amount' => 20000])->assertCreated();
        $this->goal(['month' => '2026-10', 'scope' => 'staff', 'scope_id' => $this->groomerMember->id, 'amount' => 5000])->assertCreated();
        $this->goal(['month' => '2026-10', 'scope' => 'staff', 'scope_id' => $this->groomerMember->id, 'amount' => 9])->assertUnprocessable()->assertJsonValidationErrors('scope');
        $this->goal(['month' => '2026-10', 'scope' => 'cost_center', 'amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->goal(['month' => '2026-13', 'scope' => 'products', 'amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('month');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/sales-goals/{$id}", ['amount' => '65000.50'])->assertOk()->assertJsonPath('data.amount', '65000.50');
        $this->getJson('/api/professional/sales-goals?month=2026-10')->assertOk()->assertJsonCount(3, 'data');
        $this->deleteJson("/api/professional/sales-goals/{$id}")->assertOk();
        $this->assertSame(3, SalesGoal::count());
    }

    public function test_actual_by_scope_matches_the_managerial_net_revenue_of_the_month(): void
    {
        [$store, $grooming] = $this->centers('Loja', 'Banho e tosa');
        $group = ProductGroup::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Rações', 'active' => true, 'cost_center_id' => $store->id]);
        $product = $this->makeProduct(['price' => 200, 'average_cost' => '140.0000', 'controls_stock' => false, 'product_group_id' => $group->id]);
        $bath = $this->makeService(['name' => 'Banho', 'price' => 60, 'cost_center_id' => $grooming->id]);
        $this->openRegisterFor($this->owner);
        $this->sell([['product', $product->id, null], ['service', $bath->id, $this->groomerMember->id]], discount: 13);
        $this->sellAndConsumePackage($bath);

        foreach ([['total', null, 60000], ['products', null, 10000], ['services', null, 10000], ['cost_center', $store->id, 1000], ['cost_center', $grooming->id, 1000], ['staff', $this->groomerMember->id, 100]] as [$scope, $scopeId, $amount]) {
            $this->goal(['month' => '2026-10', 'scope' => $scope, 'scope_id' => $scopeId, 'amount' => $amount])->assertCreated();
        }

        $goals = collect($this->progress()->json('data.goals'))->keyBy(fn (array $g): string => $g['scope'].':'.$g['scope_id']);

        // Receita líquida da DRE = bruta − descontos − devoluções (antes do imposto).
        $net = fn (string $query = ''): string => (function (array $lines): string {
            $line = collect($lines)->keyBy('key');

            return Money::of($line['gross_revenue']['total'])->minus($line['discounts']['total'])->minus($line['returns']['total'])->toString();
        })($this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/reports/income-statement?regime=managerial&from=2026-10-01&to=2026-10-31'.$query)->assertOk()->json('data.lines'));

        $this->assertSame($net(), $goals['total:']['actual']);
        $this->assertSame($net("&cost_center_id={$store->id}"), $goals["cost_center:{$store->id}"]['actual']);
        $this->assertSame($net("&cost_center_id={$grooming->id}"), $goals["cost_center:{$grooming->id}"]['actual']);
        // Ração (200 − 10,00 do desconto de 13,00 rateado 200:60) é produto; banho avulso + sessão de pacote (50,00) são serviços.
        $this->assertSame('190.00', $goals['products:']['actual']);
        $this->assertSame('107.00', $goals['services:']['actual']);
        $this->assertSame($goals['total:']['actual'], Money::of($goals['products:']['actual'])->plus($goals['services:']['actual'])->toString());
        // Profissional: só o banho que o tosador vendeu (a sessão do pacote não tem executor aqui).
        $this->assertSame('57.00', $goals["staff:{$this->groomerMember->id}"]['actual']);
        $this->assertSame('57.00', $goals["staff:{$this->groomerMember->id}"]['achieved_percent']);
    }

    public function test_business_days_exclude_weekends_and_holidays_and_today_counts_as_remaining(): void
    {
        $this->goal(['month' => '2026-10', 'scope' => 'total', 'amount' => 22000])->assertCreated();
        Holiday::query()->delete();

        // Outubro/2026: 22 dias úteis; antes de terça, 20/10, passaram 13 (hoje fica nos restantes).
        $this->progress()->assertJsonPath('data.business_days', ['total' => 22, 'elapsed' => 13, 'remaining' => 9]);

        Holiday::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Nossa Senhora Aparecida', 'date' => '2026-10-12', 'recurring_annually' => true, 'active' => true]);
        $this->progress()->assertJsonPath('data.business_days', ['total' => 21, 'elapsed' => 12, 'remaining' => 9])
            ->assertJsonPath('data.goals.0.needed_per_day', '2444.44');

        // Sábado útil na organização entra na conta.
        $this->clinic->update(['working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']]);
        $this->progress()->assertJsonPath('data.business_days.total', 26);
    }

    public function test_other_organization_and_permissions(): void
    {
        $id = $this->goal(['month' => '2026-10', 'scope' => 'total', 'amount' => 1000])->json('data.id');

        // Recepcionista: vet volante no papel global, sem `reports.dre.view` nem `sales-goals.manage` na clínica.
        $this->actingAs($this->receptionist, 'sanctum')->getJson('/api/professional/sales-goals/progress?month=2026-10')->assertForbidden();
        $this->actingAs($this->receptionist, 'sanctum')->getJson('/api/professional/sales-goals')->assertForbidden();
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales-goals', ['month' => '2026-10', 'scope' => 'products', 'amount' => 1])->assertForbidden();

        $other = Organization::factory()->petshop()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);
        $this->actingAs($stranger, 'sanctum')->getJson('/api/professional/sales-goals')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger, 'sanctum')->putJson("/api/professional/sales-goals/{$id}", ['amount' => 1])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson('/api/professional/sales-goals', ['month' => '2026-10', 'scope' => 'staff', 'scope_id' => $this->groomerMember->id, 'amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->actingAs($stranger, 'sanctum')->getJson('/api/professional/sales-goals/progress?month=2026-10')->assertOk()->assertJsonCount(0, 'data.goals');
    }

    /** @param  array<string, mixed>  $body */
    private function goal(array $body): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales-goals', $body);
    }

    private function progress(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/sales-goals/progress?month=2026-10')->assertOk();
    }

    /** @return list<CostCenter> */
    private function centers(string ...$names): array
    {
        return array_map(fn (string $name): CostCenter => CostCenter::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => $name, 'type' => 'store']), $names);
    }

    /** @param  list<array{0: string, 1: int, 2: int|null}>  $items */
    private function sell(array $items, float $discount = 0): void
    {
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');

        foreach ($items as [$type, $id, $staff]) {
            $total = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id, 'staff_id' => $staff])->assertCreated()->json('sale.total');
        }

        if ($discount > 0) {
            $total = $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'amount', 'discount_value' => $discount])->assertOk()->json('data.total');
        }

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => (float) $total])->assertCreated();
    }

    private function sellAndConsumePackage(\App\Models\Service $bath): void
    {
        $package = ServicePackage::create(['professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'name' => '2 banhos', 'price' => 100, 'validity_type' => 'unlimited', 'active' => true]);
        $package->items()->create(['service_id' => $bath->id, 'quantity' => 2]);
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'package', 'sellable_id' => $package->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => 100])->assertCreated();
        $sold = SoldPackage::where('sale_id', $saleId)->firstOrFail();
        $this->postJson("/api/professional/sold-packages/{$sold->id}/consume", ['sold_package_item_id' => $sold->items()->value('id'), 'quantity' => 1])->assertOk();
    }
}
