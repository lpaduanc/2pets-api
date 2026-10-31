<?php

namespace Tests\Feature\Insights;

use App\Models\CompanyClientAccount;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Métricas de rentabilidade no BI de vendas, ABC por MC, presets e as colunas novas de clientes
 * (docs/gap-petflow/PLANO.md, 1.6). Cenário: a venda do golden `rentab-01` (ração 200 com custo
 * 140 + banho 60 com custo 6,50 e comissão 17,10; desconto do cabeçalho 13,00; crédito 2,99%;
 * alíquota 6%) — MC ração 32,92 (17,33%), banho 28,27 (49,60%), venda 61,19 (24,77%).
 */
class InsightsProfitabilityTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Product $food;

    private Service $bath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->clinic->update(['effective_sales_tax_rate' => '6']);
        $this->food = $this->makeProduct(['price' => 200, 'average_cost' => '140.0000', 'controls_stock' => false]);
        $this->bath = $this->makeService(['price' => 60, 'direct_cost' => '6.50', 'commission_percent' => '28.5']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_product_and_service_dimensions_carry_the_profitability_metrics(): void
    {
        $this->sellRentab01();

        $product = $this->insights(['dimension' => 'product'])->assertOk()->json('series.0');
        $this->assertSame(
            ['190.00', '5.68', '11.40', '140.00', '0.00', '32.92', '17.33', '5.00'],
            [$product['net_revenue'], $product['card_fees'], $product['taxes'], $product['cost'], $product['commissions'], $product['contribution_margin'], $product['margin_percent'], $product['discount_percent']],
        );

        $this->assertSame('Ração Premium 3kg', $product['label']);

        $this->insights(['dimension' => 'service'])->assertOk()
            ->assertJsonCount(1, 'series')
            ->assertJsonPath('series.0.label', 'Banho porte médio')
            ->assertJsonPath('series.0.bucket', $this->bath->id)
            ->assertJsonPath('series.0.commissions', '17.10')
            ->assertJsonPath('series.0.contribution_margin', '28.27');

        // Totais: razão das somas (24,77%), nunca a média dos percentuais (33,47%).
        $this->insights(['dimension' => 'item_type'])->assertOk()
            ->assertJsonPath('totals.net_revenue', '247.00')
            ->assertJsonPath('totals.contribution_margin', '61.19')
            ->assertJsonPath('totals.margin_percent', '24.77')
            ->assertJsonPath('totals.discount_percent', '5.00');
    }

    public function test_abc_by_contribution_margin_and_presets(): void
    {
        $this->sellRentab01();
        $loser = $this->makeProduct(['name' => 'Brinde', 'price' => 30, 'average_cost' => '21.0000', 'controls_stock' => false]);
        $this->sellCourtesy($loser);

        $response = $this->insights(['dimension' => 'product', 'abc' => 'contribution_margin'])->assertOk();
        $abc = collect($response->json('series'))->pluck('abc', 'bucket');

        $this->assertSame(['class' => 'A', 'cumulative_percent' => '100.00', 'loss' => false], $abc[$this->food->id]);
        $this->assertSame(['class' => 'C', 'cumulative_percent' => null, 'loss' => true], $abc[$loser->id]);
        // O brinde entra com receita 0 e o custo dele.
        $this->assertSame(['0.00', '21.00', '-21.00', null], array_values(array_intersect_key(
            collect($response->json('series'))->firstWhere('bucket', $loser->id),
            array_flip(['net_revenue', 'cost', 'contribution_margin', 'margin_percent']),
        )));
        $this->assertSame(['product_performance', 'service_performance'], array_column($response->json('meta.presets'), 'key'));

        // Cadastro apagado: o rótulo cai na descrição da linha de venda.
        $loser->forceFill(['name' => 'Brinde renomeado'])->save();
        $loser->delete();
        $this->assertSame('Brinde', collect($this->insights(['dimension' => 'product'])->json('series'))->firstWhere('bucket', $loser->id)['label']);
        $this->assertNull(collect($this->insights(['dimension' => 'item_type'])->json('series'))->first()['label'] ?? null);
    }

    public function test_without_reports_profitability_cost_metrics_never_leak(): void
    {
        // `bi.view.own` (o override não vale para `ProductivityAuthorization`, que lê o papel):
        // ela enxerga os itens em que é responsável — a ração.
        $this->sellRentab01(productStaffId: $this->receptionistMember->id);
        $this->receptionistMember->update(['permissions' => ['bi.view.own']]);

        $response = $this->insights(['dimension' => 'product'], $this->receptionist)->assertOk();
        $this->assertSame('190.00', $response->json('series.0.net_revenue'));
        foreach (['cost', 'commissions', 'contribution_margin', 'margin_percent'] as $metric) {
            $this->assertArrayNotHasKey($metric, $response->json('series.0'));
            $this->assertArrayNotHasKey($metric, $response->json('totals'));
        }
        $this->assertSame([], $response->json('meta.presets'));

        $this->insights(['dimension' => 'product', 'metric' => 'contribution_margin'], $this->receptionist)->assertForbidden();
        $this->insights(['dimension' => 'product', 'abc' => 'contribution_margin'], $this->receptionist)->assertForbidden();

        $drill = $this->insights(['dimension' => 'product'], $this->receptionist, '/drill-down')->assertOk();
        $this->assertSame('190.00', $drill->json('data.0.net_revenue'));
        $this->assertArrayNotHasKey('cost', $drill->json('data.0'));
        $this->assertArrayNotHasKey('contribution_margin', $drill->json('summary'));

        $csv = $this->insights(['dimension' => 'product'], $this->receptionist, '/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Receita líquida', $csv);
        $this->assertStringNotContainsString('Custo', $csv);
        $this->assertStringNotContainsString('140,00', $csv);
    }

    public function test_owner_drill_down_and_export_carry_cost_columns(): void
    {
        $this->sellRentab01();

        $this->insights(['dimension' => 'product'], path: '/drill-down')->assertOk()
            ->assertJsonPath('data.0.cost', '140.00')
            ->assertJsonPath('data.0.margin_percent', '17.33')
            ->assertJsonPath('summary.contribution_margin', '32.92');

        $csv = $this->insights(['dimension' => 'product'], path: '/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Custo;Comissão;MC;"MC %"', $csv);
        $this->assertStringContainsString('190,00;5,68;11,40;140,00;0,00;32,92;17,33', $csv);
    }

    public function test_client_profile_has_average_return_and_account_balance(): void
    {
        foreach (['2026-09-01 15:00:00', '2026-09-11 15:00:00', '2026-10-11 15:00:00'] as $at) {
            Carbon::setTestNow($at);
            $this->sellCash($this->food);
        }
        Carbon::setTestNow('2026-10-20 15:00:00');
        CompanyClientAccount::query()->updateOrCreate(
            ['organization_id' => $this->clinic->id, 'professional_id' => null, 'client_id' => $this->tutor->id],
            ['current_balance' => '-150.00'],
        );

        // 01/09 → 11/09 → 11/10: intervalos 10 e 30 → 20,0 dias.
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/clients/{$this->tutor->id}/relationship-profile")
            ->assertOk()
            ->assertJsonPath('data.purchase_count', 3)
            ->assertJsonPath('data.average_return_days', '20.0')
            ->assertJsonPath('data.client_account_balance', '-150.00');
    }

    public function test_client_with_a_single_purchase_has_no_average_return(): void
    {
        $this->sellCash($this->food);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/clients/{$this->tutor->id}/relationship-profile")
            ->assertOk()
            ->assertJsonPath('data.purchase_count', 1)
            ->assertJsonPath('data.average_return_days', null)
            ->assertJsonPath('data.client_account_balance', '0.00');
    }

    /** @param  array<string, mixed>  $params */
    private function insights(array $params, ?\App\Models\User $as = null, string $path = ''): TestResponse
    {
        $query = http_build_query($params + ['from' => '2026-10-01', 'to' => '2026-10-31']);

        return $this->actingAs($as ?? $this->owner, 'sanctum')->getJson("/api/professional/insights/sales{$path}?{$query}");
    }

    private function sellRentab01(?int $productStaffId = null): void
    {
        $credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $credit->update(['max_installments' => 12]);
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/payment-methods/{$credit->id}/fee-tiers", ['tiers' => [[
            'card_brand' => null, 'installments_from' => 1, 'installments_to' => 12, 'fee_percent' => '2.99',
            'fee_fixed' => '0.00', 'first_settlement_days' => 30, 'interval_days' => 30,
        ]]])->assertOk();
        $saleId = $this->newSale();
        $this->addItem($saleId, 'product', $this->food->id, ['staff_id' => $productStaffId]);
        $this->addItem($saleId, 'service', $this->bath->id, ['staff_id' => $this->groomerMember->id]);
        $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'amount', 'discount_value' => 13])->assertOk();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $credit->id, 'amount' => '247.00', 'installments' => 1])
            ->assertCreated();
    }

    private function sellCourtesy(Product $product): void
    {
        $saleId = $this->newSale();
        $itemId = $this->addItem($saleId, 'product', $product->id);
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/sales/{$saleId}/items/{$itemId}/courtesy", ['kind' => 'gift', 'reason' => 'Brinde'])->assertOk();
        $this->postJson("/api/professional/sales/{$saleId}/finalize")->assertOk();
    }

    private function sellCash(Product $product): void
    {
        $saleId = $this->newSale();
        $this->addItem($saleId, 'product', $product->id);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => (string) $product->price])
            ->assertCreated();
    }

    private function newSale(): int
    {
        if (! \App\Models\CashRegister::where('opened_by', $this->owner->id)->whereNull('closed_at')->exists()) {
            $this->openRegisterFor($this->owner);
        }

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
