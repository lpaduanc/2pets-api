<?php

namespace Tests\Feature\Commercial\Pricing;

use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * `POST /api/professional/pricing/simulate` — docs/gap-petflow/PLANO.md, 1.4. Contrato fechado com
 * o front (feito em paralelo): corpo `{cost, price|margin_percent|markup_percent, commission_percent,
 * item_type, item_id}`, resposta em `data` com strings decimais e `alerts[{code, params}]`.
 *
 * Contexto do golden `preco-01`: alíquota efetiva 6% na organização, pior caso 4,99% (sem
 * histórico de 90 dias a média cai para o pior caso), comissão 5% → mínimo 47,62.
 */
class PricingSimulationTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private const ENDPOINT = '/api/professional/pricing/simulate';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_preco_01_through_the_endpoint_matches_the_contract(): void
    {
        $this->configureClinic();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson(self::ENDPOINT, ['cost' => '40.0000', 'price' => '60.00', 'margin_percent' => null, 'markup_percent' => null, 'commission_percent' => '5'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'cost' => '40.0000',
                'price' => '60.00',
                'markup_percent' => '50.0000',
                'margin_percent' => '33.3333',
                'minimum_price' => '47.62',
                'card_fee_reference_percent' => '4.9900',
                'card_fee_reference_mode' => 'worst_case',
                'tax_percent' => '6.0000',
                'commission_percent' => '5.0000',
                'loss_per_unit' => null,
                'alerts' => [],
            ]]);
    }

    public function test_price_below_minimum_reports_the_loss_per_unit(): void
    {
        $this->configureClinic();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson(self::ENDPOINT, ['cost' => '40', 'price' => '45', 'commission_percent' => '5'])
            ->assertOk()
            ->assertJsonPath('data.loss_per_unit', '2.62')
            ->assertJsonPath('data.alerts', [['code' => 'below_minimum', 'params' => ['loss_per_unit' => '2.62']]]);
    }

    public function test_price_by_margin_and_by_markup(): void
    {
        $this->configureClinic();
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson(self::ENDPOINT, ['cost' => '40', 'margin_percent' => '40', 'commission_percent' => '5'])
            ->assertOk()
            ->assertJsonPath('data.price', '66.67')
            ->assertJsonPath('data.margin_percent', '40.0030');
        $this->postJson(self::ENDPOINT, ['cost' => '25.50', 'markup_percent' => '80'])
            ->assertOk()
            ->assertJsonPath('data.price', '45.90')
            ->assertJsonPath('data.markup_percent', '80.0000');
    }

    public function test_commission_comes_from_the_item_when_not_informed(): void
    {
        $this->configureClinic();
        $product = $this->makeProduct(['commission_percent' => 5]);
        $service = $this->makeService(['commission_percent' => 30]);
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson(self::ENDPOINT, ['cost' => '40', 'price' => '60', 'item_type' => 'product', 'item_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.commission_percent', '5.0000')
            ->assertJsonPath('data.minimum_price', '47.62');
        // Comissão informada vence a do cadastro.
        $this->postJson(self::ENDPOINT, ['cost' => '40', 'price' => '60', 'commission_percent' => '0', 'item_type' => 'service', 'item_id' => $service->id])
            ->assertOk()
            ->assertJsonPath('data.commission_percent', '0.0000');
        // 40 / (1 − 0,4099) = 67,785… → 67,79.
        $this->postJson(self::ENDPOINT, ['cost' => '40', 'price' => '60', 'item_type' => 'service', 'item_id' => $service->id])
            ->assertOk()
            ->assertJsonPath('data.commission_percent', '30.0000')
            ->assertJsonPath('data.minimum_price', '67.79');
    }

    public function test_item_of_another_clinic_is_not_found(): void
    {
        $other = Organization::factory()->petshop()->create();
        $foreign = $this->makeProduct(['commission_percent' => 50], $other);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson(self::ENDPOINT, ['cost' => '40', 'price' => '60', 'item_type' => 'product', 'item_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');
    }

    public function test_missing_tax_rate_and_card_fee_raise_alerts_with_object_params(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson(self::ENDPOINT, ['cost' => '0', 'price' => '30'])
            ->assertOk()
            ->assertJsonPath('data.card_fee_reference_percent', '0.0000')
            ->assertJsonPath('data.tax_percent', '0.0000')
            ->assertJsonPath('data.markup_percent', null)
            ->assertJsonPath('data.minimum_price', '0.00');

        $this->assertSame(['no_card_fee_reference', 'no_tax_rate'], array_column($response->json('data.alerts'), 'code'));
        $this->assertStringContainsString('"params":{}', $response->getContent());
    }

    public function test_unreachable_minimum_price(): void
    {
        $this->configureClinic(taxRate: '15');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson(self::ENDPOINT, ['cost' => '10', 'price' => '12', 'commission_percent' => '85'])
            ->assertOk()
            ->assertJsonPath('data.minimum_price', null)
            ->assertJsonPath('data.alerts.0.code', 'minimum_price_unreachable');
    }

    public function test_exactly_one_price_target_and_a_cost_are_required(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson(self::ENDPOINT, ['cost' => '10', 'price' => '12', 'markup_percent' => '20'])->assertStatus(422)->assertJsonValidationErrors('price');
        $this->postJson(self::ENDPOINT, ['cost' => '10'])->assertStatus(422)->assertJsonValidationErrors('price');
        $this->postJson(self::ENDPOINT, ['price' => '12'])->assertStatus(422)->assertJsonValidationErrors('cost');
        $this->postJson(self::ENDPOINT, ['cost' => '-1', 'price' => '12'])->assertStatus(422)->assertJsonValidationErrors('cost');
        $this->postJson(self::ENDPOINT, ['cost' => '10', 'margin_percent' => '100'])->assertStatus(422)->assertJsonValidationErrors('margin_percent');
        $this->postJson(self::ENDPOINT, ['cost' => '10.00001', 'price' => '12'])->assertStatus(422)->assertJsonValidationErrors('cost');
        $this->postJson(self::ENDPOINT, ['cost' => '10', 'price' => '12', 'item_type' => 'product'])->assertStatus(422)->assertJsonValidationErrors('item_id');
    }

    public function test_tutor_cannot_simulate(): void
    {
        $this->actingAs($this->tutor, 'sanctum')
            ->postJson(self::ENDPOINT, ['cost' => '10', 'price' => '12'])
            ->assertForbidden();
    }

    private function configureClinic(string $taxRate = '6'): void
    {
        $this->clinic->update(['effective_sales_tax_rate' => $taxRate]);
        $credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $credit->update(['max_installments' => 12]);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$credit->id}/fee-tiers", ['tiers' => [
                ['card_brand' => null, 'installments_from' => 1, 'installments_to' => 1, 'fee_percent' => '2.99', 'fee_fixed' => '0.00', 'first_settlement_days' => 30, 'interval_days' => 30],
                ['card_brand' => null, 'installments_from' => 2, 'installments_to' => 12, 'fee_percent' => '4.99', 'fee_fixed' => '0.00', 'first_settlement_days' => 30, 'interval_days' => 30],
            ]])
            ->assertOk();
    }
}
