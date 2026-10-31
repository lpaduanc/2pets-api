<?php

namespace Tests\Feature\Commercial\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Configurações da precificação (docs/gap-petflow/PLANO.md, 1.4): alíquota efetiva e taxa de
 * referência no GET/PUT de fiscal-settings (junto das da 1.1), e o teto de desconto
 * `max_discount_percent` no cadastro de produto e de serviço.
 */
class PricingSettingsTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_fiscal_settings_expose_the_pricing_defaults(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/fiscal-settings')
            ->assertOk()
            ->assertJsonPath('data.effective_sales_tax_rate', null)
            ->assertJsonPath('data.card_fee_reference_mode', 'weighted_90d');
    }

    public function test_fiscal_settings_update_the_pricing_fields(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/professional/fiscal-settings', ['effective_sales_tax_rate' => '6.5', 'card_fee_reference_mode' => 'worst_case'])
            ->assertOk()
            ->assertJsonPath('data.effective_sales_tax_rate', '6.5000')
            ->assertJsonPath('data.card_fee_reference_mode', 'worst_case')
            // As configurações da 1.1 seguem intactas.
            ->assertJsonPath('data.card_surcharge_on_invoice', true);

        $this->putJson('/api/professional/fiscal-settings', ['effective_sales_tax_rate' => null])
            ->assertOk()
            ->assertJsonPath('data.effective_sales_tax_rate', null)
            ->assertJsonPath('data.card_fee_reference_mode', 'worst_case');
    }

    public function test_fiscal_settings_reject_invalid_pricing_values(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->putJson('/api/professional/fiscal-settings', ['effective_sales_tax_rate' => '100.01', 'card_fee_reference_mode' => 'media'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['effective_sales_tax_rate', 'card_fee_reference_mode']);
        $this->putJson('/api/professional/fiscal-settings', ['effective_sales_tax_rate' => '6.12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_sales_tax_rate');
        $this->putJson('/api/professional/fiscal-settings', ['effective_sales_tax_rate' => '-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_sales_tax_rate');
    }

    public function test_receptionist_cannot_change_pricing_settings(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->putJson('/api/professional/fiscal-settings', ['effective_sales_tax_rate' => '6'])
            ->assertForbidden();

        $this->assertNull($this->clinic->fresh()->effective_sales_tax_rate);
    }

    public function test_product_accepts_and_returns_max_discount_percent(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $id = $this->postJson('/api/professional/products', ['name' => 'Ração', 'sku' => 'R-1', 'price' => '100.00', 'max_discount_percent' => '12.5'])
            ->assertCreated()
            ->assertJsonPath('data.max_discount_percent', '12.5000')
            ->json('data.id');

        $this->putJson("/api/professional/products/{$id}", ['max_discount_percent' => null])
            ->assertOk()
            ->assertJsonPath('data.max_discount_percent', null);
        $this->putJson("/api/professional/products/{$id}", ['max_discount_percent' => '100.0001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_discount_percent');
    }

    public function test_service_accepts_and_returns_max_discount_percent(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $id = $this->postJson('/api/professional/services', ['name' => 'Banho', 'category' => 'grooming', 'price' => 60, 'duration' => 60, 'max_discount_percent' => '10'])
            ->assertCreated()
            ->assertJsonPath('service.max_discount_percent', '10.0000')
            ->json('service.id');

        $this->putJson("/api/professional/services/{$id}", ['max_discount_percent' => '15.25'])
            ->assertOk()
            ->assertJsonPath('service.max_discount_percent', '15.2500');
        $this->putJson("/api/professional/services/{$id}", ['max_discount_percent' => '-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_discount_percent');
    }
}
