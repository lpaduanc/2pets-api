<?php

namespace Tests\Feature\Finance;

use App\Enums\OrganizationRole;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PaymentMethod;
use App\Services\Finance\FinancialCategoryProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * DRE gerencial (docs/gap-petflow/PLANO.md, 1.7), de ponta a ponta — golden
 * `dre-gerencial-periodo` montado de verdade: a venda paga do `rentab-01` (1.5), aluguel 30,00
 * (`operating`), juros 5,00 (`financial`) e, de FORA, compra de mercadoria 500,00
 * (`Fornecedores` → `inventory_purchase`), taxa de cartão 7,39 lançada à mão (`card_fee`) e a
 * receita que o PDV lançou da própria venda. Lucro líquido 26,19; equilíbrio 121,10.
 */
class ManagerialIncomeStatementTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private const PERIOD = 'from=2026-10-01&to=2026-10-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->clinic->update(['effective_sales_tax_rate' => '6']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_managerial_regime_matches_the_golden_and_keeps_double_counting_out(): void
    {
        $this->sellRentab01();
        $this->ledgerScenario();

        $data = $this->statement('managerial')->assertOk()->json('data');
        $lines = collect($data['lines'])->keyBy('key');

        $this->assertSame('managerial', $data['regime']);
        $this->assertSame(
            ['260.00', '13.00', '0.00', '14.82', '232.18', '146.50', '85.68', '7.39', '17.10', '61.19', '0.00', '30.00', '31.19', '-5.00', '26.19'],
            $lines->pluck('total')->all(),
        );
        $this->assertSame(['products' => '140.00', 'services' => '6.50'], $lines['cost_of_sales']['breakdown']);
        $this->assertSame('24.77', $data['margin_percent']);
        $this->assertSame(['amount' => '121.10', 'basis' => 'operating_expenses', 'fixed_expenses' => '30.00', 'reason' => null], $data['break_even']);

        $this->assertSame(['computed', 'ledger'], [$lines['card_fees']['source'], $lines['operating_expenses']['source']]);
        $this->assertSame(['indicator' => 'sales', 'presets' => ['product_performance', 'service_performance'], 'metric' => 'cost', 'from' => '2026-10-01', 'to' => '2026-10-31'], $lines['cost_of_sales']['drill_down']);
        $this->assertNull($lines['operating_expenses']['drill_down']);
    }

    public function test_cash_and_accrual_regimes_are_untouched(): void
    {
        $this->sellRentab01();
        $this->ledgerScenario();

        // Competência continua somando TODO lançamento, por categoria-folha: receita do PDV
        // 247,00; despesas 30 + 500 + 7,39 + 5.
        $accrual = $this->statement('accrual')->assertOk()->json('data');
        $leaves = collect($accrual['categories'])->where('is_group', false)->groupBy('nature')->map->sum('total');
        $this->assertEqualsWithDelta(247.0, $leaves['revenue'], 0.01);
        $this->assertEqualsWithDelta(542.39, $leaves['expense'], 0.01);
        $this->assertArrayNotHasKey('lines', $accrual);
    }

    public function test_seed_categories_get_the_default_treatment_and_the_clinic_can_edit_it(): void
    {
        app(FinancialCategoryProvisioner::class)->ensureDefaults($this->owner);
        $categories = FinancialCategory::where('organization_id', $this->clinic->id)->pluck('dre_treatment', 'name')->map->value;

        $this->assertSame(['card_fee', 'inventory_purchase', 'operating', 'ignore'], [
            $categories['Taxas de cartão'], $categories['Fornecedores'], $categories['Despesas operacionais'], $categories['Produtos'],
        ]);

        $id = FinancialCategory::where('organization_id', $this->clinic->id)->where('name', 'Despesas operacionais')->value('id');
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/financial-categories/{$id}", ['dre_treatment' => 'financial'])
            ->assertOk()
            ->assertJsonPath('data.dre_treatment', 'financial')
            ->assertJsonPath('data.dre_treatment_label', 'Resultado financeiro');
        $this->putJson("/api/professional/financial-categories/{$id}", ['dre_treatment' => 'lucro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dre_treatment');
    }

    public function test_non_positive_margin_has_no_break_even(): void
    {
        $product = $this->makeProduct(['price' => 100, 'average_cost' => '120.0000', 'controls_stock' => false]);
        $saleId = $this->newSale();
        $itemId = $this->addItem($saleId, 'product', $product->id);
        // Abaixo do custo só fecha como brinde (1.4): receita 0, custo 120 → MC −120.
        $this->putJson("/api/professional/sales/{$saleId}/items/{$itemId}/courtesy", ['kind' => 'gift', 'reason' => 'Brinde'])->assertOk();
        $this->postJson("/api/professional/sales/{$saleId}/finalize")->assertOk();

        $data = $this->statement('managerial')->assertOk()->json('data');

        $this->assertSame('-120.00', collect($data['lines'])->firstWhere('key', 'contribution_margin')['total']);
        $this->assertNull($data['margin_percent']);
        $this->assertSame([null, 'no_revenue'], [$data['break_even']['amount'], $data['break_even']['reason']]);
    }

    public function test_managerial_needs_reports_profitability_in_every_organization(): void
    {
        // Dona desta clínica, atendente em outra: o custo de lá não abre.
        $other = Organization::factory()->petshop()->create();
        OrganizationMember::factory()->for($other, 'organization')->create(['user_id' => $this->owner->id, 'role' => OrganizationRole::RECEPTIONIST->value]);

        $this->statement('managerial')->assertForbidden()->assertJsonPath('message', 'A DRE gerencial mostra custo e margem: é preciso a permissão de rentabilidade (reports.profitability).');
        $this->statement('accrual')->assertOk();
    }

    private function statement(string $regime): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/reports/income-statement?regime={$regime}&".self::PERIOD);
    }

    private function ledgerScenario(): void
    {
        $provisioner = app(FinancialCategoryProvisioner::class);
        $rent = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
        $interest = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Juros', 'nature' => 'expense', 'kind' => 'entry', 'active' => true, 'dre_treatment' => 'financial']);
        $suppliers = $provisioner->systemCategory($this->owner, \App\Enums\FinancialNature::EXPENSE, 'Fornecedores', 'Custos e despesas');
        $cardFees = $provisioner->systemCategory($this->owner, \App\Enums\FinancialNature::EXPENSE, 'Taxas de cartão', 'Taxas e tarifas');

        foreach ([[$rent, '30.00'], [$interest, '5.00'], [$suppliers, '500.00'], [$cardFees, '7.39']] as [$category, $amount]) {
            FinancialEntry::create([
                'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'financial_category_id' => $category->id,
                'description' => $category->name, 'nature' => 'expense', 'due_date' => '2026-10-10', 'accrual_date' => '2026-10-10',
                'amount' => $amount, 'net_amount' => $amount, 'status' => 'open', 'created_by' => $this->owner->id,
            ]);
        }
    }

    private function sellRentab01(): void
    {
        $credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $credit->update(['max_installments' => 12]);
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/payment-methods/{$credit->id}/fee-tiers", ['tiers' => [[
            'card_brand' => null, 'installments_from' => 1, 'installments_to' => 12, 'fee_percent' => '2.99',
            'fee_fixed' => '0.00', 'first_settlement_days' => 30, 'interval_days' => 30,
        ]]])->assertOk();
        $saleId = $this->newSale();
        $this->addItem($saleId, 'product', $this->makeProduct(['price' => 200, 'average_cost' => '140.0000', 'controls_stock' => false])->id);
        $this->addItem($saleId, 'service', $this->makeService(['price' => 60, 'direct_cost' => '6.50', 'commission_percent' => '28.5'])->id, ['staff_id' => $this->groomerMember->id]);
        $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'amount', 'discount_value' => 13])->assertOk();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $credit->id, 'amount' => '247.00', 'installments' => 1])->assertCreated();
    }

    private function newSale(): int
    {
        $this->openRegisterFor($this->owner);

        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $body */
    private function addItem(int $saleId, string $type, int $id, array $body = []): int
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id] + $body)->assertCreated()->json('data.id');
    }
}
