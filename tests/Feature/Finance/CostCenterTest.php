<?php

namespace Tests\Feature\Finance;

use App\Models\CostCenter;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ProductGroup;
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
 * Centro de custo e rateio — docs/gap-petflow/PLANO.md, 2.4 (golden `cost-center.json`): CRUD
 * com permissão no cargo da organização, rateio do lançamento (Σ = 100,0000 exato), rateio padrão
 * da categoria, parcelas com rateio, herança do grupo e a DRE gerencial por centro somando,
 * centavo a centavo, à DRE total (inclusive sessão de pacote da 2.7).
 */
class CostCenterTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private const PERIOD = 'from=2026-10-01&to=2026-10-31';

    private FinancialCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->clinic->update(['effective_sales_tax_rate' => '6']);
        $this->rent = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_crud_needs_the_role_in_the_organization_and_stays_inside_it(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/cost-centers', ['name' => 'Banho e tosa', 'type' => 'grooming'])
            ->assertCreated()->assertJsonPath('data.type_label', 'Banho e tosa')->json('data.id');
        $this->putJson("/api/professional/cost-centers/{$id}", ['name' => 'Estética', 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->getJson('/api/professional/cost-centers')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(5, 'types');
        $this->postJson('/api/professional/cost-centers', ['name' => 'X', 'type' => 'spa'])->assertUnprocessable()->assertJsonValidationErrors('type');

        // A recepcionista é vet volante no papel global, mas não gere o plano de contas da clínica.
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/cost-centers', ['name' => 'Loja', 'type' => 'store'])->assertForbidden();
        $this->actingAs($this->receptionist, 'sanctum')->getJson('/api/professional/cost-centers')->assertOk()->assertJsonCount(1, 'data');

        [$stranger] = $this->otherOrganization();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/professional/cost-centers/{$id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/professional/cost-centers')->assertOk()->assertJsonPath('data.*.name', ['Deles']);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/professional/cost-centers/{$id}")->assertOk();
        $this->assertSoftDeleted('cost_centers', ['id' => $id]);
    }

    public function test_entry_allocation_must_sum_exactly_100_and_splits_the_net_amount(): void
    {
        [$a, $b, $c] = $this->centers('Loja', 'Banho', 'Clínica');
        $body = ['financial_category_id' => $this->rent->id, 'description' => 'Aluguel', 'nature' => 'expense', 'due_date' => '2026-10-10', 'amount' => 1000];

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/financial-entries', $body + ['allocations' => [
            ['cost_center_id' => $a->id, 'percent' => '33.3333'], ['cost_center_id' => $b->id, 'percent' => '33.3333'], ['cost_center_id' => $c->id, 'percent' => '33.3333'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('allocations');
        $this->assertSame(0, FinancialEntry::count());

        $id = $this->postJson('/api/professional/financial-entries', $body + ['allocations' => [
            ['cost_center_id' => $a->id, 'percent' => '33.3333'], ['cost_center_id' => $b->id, 'percent' => '33.3333'], ['cost_center_id' => $c->id, 'percent' => '33.3334'],
        ]])->assertCreated()
            ->assertJsonPath('data.0.allocations.2.amount', '333.34')
            ->assertJsonPath('data.0.allocations.0.cost_center_name', 'Loja')
            ->json('data.0.id');

        // Valor muda → mesmos percentuais, valores refeitos; `allocations: []` = "Não rateado".
        $this->putJson("/api/professional/financial-entries/{$id}", ['amount' => '100.01'])->assertOk()
            ->assertJsonPath('data.allocations.*.amount', ['33.34', '33.33', '33.34']);
        $this->putJson("/api/professional/financial-entries/{$id}", ['allocations' => []])->assertOk()->assertJsonCount(0, 'data.allocations');

        [, $foreignCenter] = $this->otherOrganization();
        $this->postJson('/api/professional/financial-entries', $body + ['allocations' => [['cost_center_id' => $foreignCenter->id, 'percent' => 100]]])
            ->assertUnprocessable()->assertJsonValidationErrors('allocations.0.cost_center_id');
    }

    public function test_category_default_allocation_applies_to_entries_born_without_allocation_and_follows_installments(): void
    {
        [$store, $admin] = $this->centers('Loja', 'Administrativo');
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/financial-categories/{$this->rent->id}", ['default_allocation' => [
            ['cost_center_id' => $store->id, 'percent' => 60], ['cost_center_id' => $admin->id, 'percent' => 39.9999],
        ]])->assertUnprocessable()->assertJsonValidationErrors('default_allocation');
        $this->putJson("/api/professional/financial-categories/{$this->rent->id}", ['default_allocation' => [
            ['cost_center_id' => $store->id, 'percent' => 60], ['cost_center_id' => $admin->id, 'percent' => 40],
        ]])->assertOk()->assertJsonPath('data.default_allocation.1.percent', 40);

        $installments = $this->postJson('/api/professional/financial-entries', [
            'financial_category_id' => $this->rent->id, 'description' => 'Reforma', 'nature' => 'expense', 'due_date' => '2026-10-10', 'amount' => '1000.00', 'installments' => 3,
        ])->assertCreated()->json('data');

        $this->assertCount(3, $installments);
        foreach ($installments as $installment) {
            $amounts = array_column($installment['allocations'], 'amount');
            $this->assertSame((string) $installment['net_amount'], Money::sum($amounts)->toString());
            $this->assertSame(['60.0000', '40.0000'], array_column($installment['allocations'], 'percent'));
        }

        // Lançamento automático (aqui direto pelo model, como os recorders) também recebe o padrão.
        $auto = FinancialEntry::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'financial_category_id' => $this->rent->id,
            'description' => 'Auto', 'nature' => 'expense', 'due_date' => '2026-10-10', 'accrual_date' => '2026-10-10', 'amount' => '10.01', 'net_amount' => '10.01', 'status' => 'open', 'created_by' => $this->owner->id]);
        $this->assertSame(['6.01', '4.00'], $auto->allocations()->pluck('amount')->map(fn ($v): string => (string) $v)->all());

        // Rateio explícito vazio ganha do padrão.
        $this->postJson('/api/professional/financial-entries', [
            'financial_category_id' => $this->rent->id, 'description' => 'Sem rateio', 'nature' => 'expense', 'due_date' => '2026-10-10', 'amount' => 50, 'allocations' => [],
        ])->assertCreated()->assertJsonCount(0, 'data.0.allocations');
    }

    public function test_managerial_statement_by_cost_center_inherits_from_the_group_and_sums_to_the_total(): void
    {
        [$store, $grooming, $admin] = $this->centers('Loja', 'Banho e tosa', 'Administrativo');
        $petFood = ProductGroup::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Rações', 'active' => true, 'cost_center_id' => $store->id]);
        $product = $this->makeProduct(['price' => 200, 'average_cost' => '140.0000', 'controls_stock' => false, 'product_group_id' => $petFood->id]);
        $bath = $this->makeService(['name' => 'Banho', 'price' => 60, 'direct_cost' => '6.50', 'commission_percent' => '10', 'cost_center_id' => $grooming->id]);
        $nail = $this->makeService(['name' => 'Unha', 'price' => 20, 'commission_percent' => '0']);
        $this->openRegisterFor($this->owner);

        $this->sell([['product', $product->id], ['service', $bath->id], ['service', $nail->id]], discount: 13);
        $this->sellAndConsumePackage($bath);
        $this->expense('30.00', [['cost_center_id' => $store->id, 'percent' => 50], ['cost_center_id' => $admin->id, 'percent' => 50]]);
        $this->expense('7.77', null);

        $data = $this->statement('&breakdown=cost_center')->json('data');
        $total = collect($data['lines'])->pluck('total', 'key');
        $centers = collect($data['cost_centers'])->keyBy(fn (array $c): string => (string) $c['cost_center']['id']);

        $this->assertSame(['Administrativo', 'Banho e tosa', 'Loja', 'Não rateado'], collect($data['cost_centers'])->pluck('cost_center.name')->all());
        foreach ($total as $key => $value) {
            $this->assertSame($value, Money::sum($centers->map(fn (array $c): string => collect($c['lines'])->firstWhere('key', $key)['total'])->all())->toString(), "linha {$key}");
        }

        // Ração herda "Loja" do grupo; despesa 50/50; o serviço sem centro e a despesa sem rateio são "Não rateado".
        $line = fn (string $center, string $key): string => collect($centers[$center]['lines'])->firstWhere('key', $key)['total'];
        $this->assertSame('200.00', $line((string) $store->id, 'gross_revenue'));
        $this->assertSame('15.00', $line((string) $store->id, 'operating_expenses'));
        $this->assertSame('15.00', $line((string) $admin->id, 'operating_expenses'));
        $this->assertSame('20.00', $line('unallocated', 'gross_revenue'));
        $this->assertSame('7.77', $line('unallocated', 'operating_expenses'));
        // Banho avulso 60,00 + sessão de pacote 50,00 (pacote de 2 banhos por 100,00) no centro do serviço.
        $this->assertSame('110.00', $line((string) $grooming->id, 'gross_revenue'));

        $filtered = collect($this->statement("&cost_center_id={$grooming->id}")->json('data.lines'))->pluck('total', 'key');
        $this->assertSame(collect($centers[(string) $grooming->id]['lines'])->pluck('total', 'key')->all(), $filtered->all());
        $this->statement('&cost_center_id=unallocated')->assertJsonPath('data.cost_center_id', 'unallocated');

        [, $foreignCenter] = $this->otherOrganization();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/reports/income-statement?regime=managerial&'.self::PERIOD."&cost_center_id={$foreignCenter->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
    }

    public function test_product_service_and_group_crud_take_a_cost_center_from_the_same_organization(): void
    {
        [$store] = $this->centers('Loja');
        [, $foreign] = $this->otherOrganization();

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/product-groups', ['name' => 'Rações', 'cost_center_id' => $store->id])
            ->assertCreated()->assertJsonPath('data.cost_center_id', $store->id);
        $this->postJson('/api/professional/product-groups', ['name' => 'Outro', 'cost_center_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
        $this->postJson('/api/professional/products', ['name' => 'Ração', 'sku' => 'R-1', 'price' => 10, 'cost_center_id' => $store->id])
            ->assertCreated()->assertJsonPath('data.cost_center_id', $store->id);
        $this->postJson('/api/professional/services', ['name' => 'Banho', 'category' => 'grooming', 'duration' => 30, 'price' => 50, 'cost_center_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
    }

    /** @return list<CostCenter> */
    private function centers(string ...$names): array
    {
        return array_map(fn (string $name): CostCenter => CostCenter::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => $name, 'type' => 'store']), $names);
    }

    /** @return array{0: User, 1: CostCenter} */
    private function otherOrganization(): array
    {
        $other = Organization::factory()->petshop()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);

        return [$stranger, CostCenter::create(['organization_id' => $other->id, 'professional_id' => $stranger->id, 'name' => 'Deles', 'type' => 'store'])];
    }

    /** @param  list<array{0: string, 1: int}>  $items */
    private function sell(array $items, float $discount = 0): void
    {
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');

        foreach ($items as [$type, $id]) {
            $total = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id, 'staff_id' => $this->groomerMember->id])->assertCreated()->json('sale.total');
        }

        if ($discount > 0) {
            $total = $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'amount', 'discount_value' => $discount])->assertOk()->json('data.total');
        }

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => (float) $total])
            ->assertCreated()->assertJsonPath('sale.status', 'paid');
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

    /** @param  list<array<string, mixed>>|null  $allocations */
    private function expense(string $amount, ?array $allocations): void
    {
        $body = ['financial_category_id' => $this->rent->id, 'description' => 'Despesa', 'nature' => 'expense', 'due_date' => '2026-10-10', 'amount' => $amount];
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/financial-entries', $body + ($allocations === null ? [] : ['allocations' => $allocations]))->assertCreated();
    }

    private function statement(string $extra = ''): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/reports/income-statement?regime=managerial&'.self::PERIOD.$extra)->assertOk();
    }
}
